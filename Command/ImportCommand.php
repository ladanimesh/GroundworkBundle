<?php

namespace Lankerd\GroundworkBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class ImportCommand extends ContainerAwareCommand
{
    protected $services;

    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('groundwork:import:all')
            // the short description shown while running "php bin/console list"
            ->setDescription('Imports all records.');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|null|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        /*Grab the CSV Directory from the configuration file*/
        $importPath = $this->getContainer()->getParameter('import_directory');
        /*Cut out '..' and '.' when we scan the csv directory, effectively grabbing [all] file [name(s)] in the process*/
        $filesToImport = array_diff(scandir($importPath), array('.', '..'));

        /*Grab all of the services that will be unpacked*/
        $services = $this->getContainer()->getParameter('lankerd_groundwork.import_services');

        $filenames = [];
        /*We'll set a global that's watching our service listing[s]*/
        foreach ($filesToImport as $key => $fileToImport) {
            /*Strip the extension off of the filename in order to run the file in it's correct */
            $filenames[] = preg_replace('/\\.[^.\\s]{3,4}$/', '', $fileToImport);
        }

        $this->services = $filenames;

        $this->processServices($services, $importPath, $filesToImport);

    }

    public function processServices($services, $importPath, $filesToImport)
    {
        foreach ($services as $service) {
            if (is_array($service)) {
                $this->runServices(key($service), $importPath, $filesToImport);
                $this->processServices($service, $importPath, $filesToImport);
            }else{
                $this->runServices($service, $importPath, $filesToImport);
            }
        }
    }

    /**
     * @param $service
     * @param $importPath
     * @param $filesToImport
     */
    private function runServices($service, $importPath, $filesToImport)
    {
        if ($service == 'user') {
                $this->getContainer()->get('user.model.layout')->makeUsers($filesToImport);
                $this->getContainer()->get('user.model.layout')->setOptions([
                    'filesToImport' => $filesToImport,
                    'importPath' => $importPath,
                    'serviceListing' => $this->services
                ]);
        } else {
            foreach ($filesToImport as $key => $fileToImport) {
                /*Strip the extension off of the filename in order to run the file in it's correct */
                $filename = preg_replace('/\\.[^.\\s]{3,4}$/', '', $fileToImport);
                try {
                    $this->getContainer()->get($filename);
                } catch (\Exception $e) {
                    unset($filesToImport[$key]);
                    break;
                }
                /*Let's remove the oncoming file*/
                unset($filesToImport[$key]);
                if ($service == $filename) {
                    echo "\n=============$filename=============\n";
                    $this->getContainer()
                        ->get($service)
                        ->setOptions([
                            'filesToImport' => $filesToImport,
                            'importPath' => $importPath,
                            'serviceListing' => $this->services
                        ]);
                    $this->getContainer()
                        ->get($service)
                        ->readCSV($importPath.$fileToImport);
                }
            }
        }
    }
}