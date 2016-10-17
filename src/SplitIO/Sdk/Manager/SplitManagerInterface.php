<?php
namespace SplitIO\Sdk\Manager;

interface SplitManagerInterface
{
    /**
     * @return array
     */
    public function splits();

    /**
     * @param $featureName
     * @return \SplitIO\Sdk\Manager\SplitView
     */
    public function split($featureName);
}
