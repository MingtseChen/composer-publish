<?php

namespace Starlux\Publish\Command;

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class PublishCommand
 * @package Starlux\Publish\Command
 */
class PublishCommand extends BaseCommand
{

    /**
     *
     */
    const FILENAME_LENGTH = 13;

    /**
     *
     */
    const HEALTH_CHECK_ENDPOINT = '/check';

    const REPO_UPLOAD_ENDPOINT = '/package/upload/composer';

    /**
     * @var
     */
    protected $cacheZipFileDir;

    /**
     * @var
     */
    protected $remoteUrl;

    /**
     *
     */
    protected function configure()
    {
        $this->setName('publish')
//            ->addUsage('publish')
            ->setDescription('Publish a repo to Starlux server')
            ->setHelp('Wrapper for Starlux PHP repo cache proxy server ')
            ->addOption('bump', 'b', InputOption::VALUE_REQUIRED, 'Version to publish')
            ->addOption('remote', 'r', InputOption::VALUE_REQUIRED, 'Custom remote server');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $semver = $input->getOption('bump');
        if ($semver) {
            return;
        } else {
            $helper = $this->getHelper('question');
            $question = new Question('<comment>Version to bump: </comment>', '1.0.0');
            $input->setOption('bump', $helper->ask($input, $output, $question));
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatHelper = $this->getHelper('formatter');
        $bumpVersion = $input->getOption('bump');

        $currentDirInfo = $formatHelper
            ->formatSection('Info',
                'Working on '.'<comment>'.$this->getCurrentWorkDir().'</comment>',
                'fg=cyan');
        $output->writeln($currentDirInfo);

        $cacheDirInfo = $formatHelper
            ->formatSection('Info',
                'Config cache to '.'<comment>'.$this->getCacheDir().'</comment>',
                'fg=cyan'
            );
        $output->writeln($cacheDirInfo);

        $checkComposerDotJson = $formatHelper
            ->formatSection('Preflight 1/2',
                'Validate local project...',
                'fg=cyan'
            );
        if ($this->hasComposerDotJson()) {
            $composerCheckMessage = '<fg=green>Pass</>';
            $output->writeln($checkComposerDotJson.' '.$composerCheckMessage);
        } else {
            $composerCheckMessage = '<fg=red>No composer.json found. Abort</>';
            $output->writeln($checkComposerDotJson.' '.$composerCheckMessage);
            return 1;
        }


        $checkRemoteRepo = $formatHelper
            ->formatSection('Preflight 2/2',
                'Validate remote repos',
                'fg=cyan');
        $output->writeln($checkRemoteRepo);


        $repos = $this->getAllRepositories();
        $repoCheckFlag = false;
        foreach ($repos as $repo) {
            $isSuccess = $this->checkRemoteRepository($repo['url']);
            $repoCheckFlag = $isSuccess or $repoCheckFlag;

            $message = 'Connecting remote <comment>'.$repo['url'].'</comment>';
            if ($isSuccess) {
                $this->setRemoteUrl($repo['url']);
                $statusMessage = $formatHelper
                    ->formatSection('OK', $message, 'fg=green');
            } else {
                $statusMessage = $formatHelper
                    ->formatSection('Fail', $message, 'fg=yellow');
            }
            $output->writeln($statusMessage);
        }

        if ($repoCheckFlag == false) {
            $repoError = $formatHelper
                ->formatSection('Error', '<fg=red>No usable remote repo server</>', 'error');
            $output->writeln($repoError);
            return 1;
        }

        $zipMessage = $formatHelper
            ->formatSection('Zip', 'Compressing package', 'fg=cyan');
        $output->writeln($zipMessage);
        $this->zipPackage();

        $publishMessage = $formatHelper
            ->formatSection('Upload', 'Publish package', 'fg=cyan');
        $output->writeln($publishMessage);
        $publishSuccess = $this->publish($bumpVersion, $this->getRemoteUrl());

        //cleanup no mater publish success or fail
        $this->cleanUp();

        if ($publishSuccess) {
            $cleanUpMessage = $formatHelper
                ->formatSection('Success', 'Successfully upload package ver.'.$bumpVersion);
            $output->writeln($cleanUpMessage);
            return 0;
        } else {
            $cleanUpMessage = $formatHelper
                ->formatSection('Fail', 'Fail to upload package', 'fg=red');
            $output->writeln($cleanUpMessage);
            return 1;
        }

    }

    private function getCurrentWorkDir()
    {
        return getcwd();
    }

    /**
     * @return bool|int|mixed|string|null
     */
    private function getCacheDir()
    {
        return $this->getConfig()->get('cache-dir');
    }

    /**
     * @return \Composer\Config
     */
    private function getConfig()
    {
        $composer = $this->getComposer(false);

        if ($composer) {
            $config = $composer->getConfig();
        } else {
            $config = Factory::createConfig();
        }

        return $config;
    }

    /**
     * @return bool
     */
    private function hasComposerDotJson()
    {
        $currentPath = getcwd();
        $target = 'composer.json';

        return file_exists($currentPath.'/'.$target);
    }

    /**
     * @return array|array[]
     */
    private function getAllRepositories()
    {
        return $this->getConfig()->getRepositories();
    }

    /**
     * @param $url
     * @return bool
     */
    private function checkRemoteRepository($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url.self::HEALTH_CHECK_ENDPOINT);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $httpStatus == 200;
    }

    /**
     * @param  string  $remoteUrl
     */
    private function setRemoteUrl(string $remoteUrl): void
    {
        $this->remoteUrl = $remoteUrl;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function zipPackage()
    {
        $zip = (new \Starlux\Publish\Zip());
        $file = $this->getCacheDir().'/'."{$this->randomFilename()}.zip";
        $zip->archive($this->getCurrentWorkDir(), $file, 'zip');

        $this->setCacheZipFileDir($file);

        return true;
    }

    /**
     * @return false|string
     * @throws \Exception
     */
    private function randomFilename()
    {
        if (function_exists("random_bytes")) {
            $bytes = random_bytes(ceil(self::FILENAME_LENGTH / 2));
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes(ceil(self::FILENAME_LENGTH / 2));
        } else {
            throw new \Exception("No cryptographically secure random function available");
        }
        return substr(bin2hex($bytes), 0, self::FILENAME_LENGTH);
    }

    /**
     * @param  mixed  $cacheZipFileDir
     */
    private function setCacheZipFileDir($cacheZipFileDir): void
    {
        $this->cacheZipFileDir = $cacheZipFileDir;
    }

    private function publish($version, $url): bool
    {
        $file = $this->getCacheZipFileDir();
        if (file_exists($file)) {

            $binary = file_get_contents($file);
            $payload = $this->generateRequest($version, $binary);
        } else {
            throw new \Exception('File not found');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload.self::REPO_UPLOAD_ENDPOINT);
//        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $httpStatus == 200;
    }

    /**
     * @return mixed
     */
    private function getCacheZipFileDir()
    {
        return $this->cacheZipFileDir;
    }

    private function generateRequest($version, $binary)
    {
        return json_encode(array('version' => $version, 'file' => base64_encode($binary)));
    }

    /**
     * @return string|null
     */
    private function getRemoteUrl()
    {
        return $this->remoteUrl;
    }

    private function cleanUp()
    {
        (new Filesystem())->remove($this->getCacheZipFileDir());
    }
}