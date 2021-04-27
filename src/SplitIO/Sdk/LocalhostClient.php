<?php
namespace SplitIO\Sdk;

use Symfony\Component\Yaml\Parser;
use SplitIO\Grammar\Condition\Partition\TreatmentEnum;
use SplitIO\Sdk\Validator\InputValidator;
use SplitIO\Split as SplitApp;

class LocalhostClient implements ClientInterface
{
    private $splits = null;

    /**
     * Try to get the user home directory
     * @return string|null
     * @codeCoverageIgnore
     */
    private function getUserHome()
    {
        $userData = posix_getpwuid(posix_getuid());
        return (isset($userData['dir'])) ? $userData['dir'] : null;
    }

    private function getExistingFile($path)
    {
        if (!is_null($path) && file_exists($path)) {
            return $path;
        }
        if (file_exists($this->getUserHome().'/split.yaml')) {
            return $this->getUserHome().'/split.yaml';
        }
        if (file_exists($this->getUserHome().'/split.yml')) {
            return $this->getUserHome().'/split.yml';
        }
        if (file_exists($this->getUserHome().'/.split')) {
            SplitApp::logger()->warning("Localhost mode: .split mocks will be deprecated soon in favor of YAML "
            . "files, which provide more targeting power. Take a look in our documentation.");
            return $this->getUserHome().'/.split';
        }
        return null;
    }

    /**
     * Constructor of the FakeClient for development purpose
     * @param null $splitFilePath
     * @throws \Exception
     */
    public function __construct($splitFilePath = null)
    {
        $filePath = $this->getExistingFile($splitFilePath);
        // @codeCoverageIgnoreStart
        if (!is_null($filePath)) {
            if (preg_match('/(\.yml$|\.yaml$)/i', $filePath)) {
                $this->loadSplitsFromYAML($filePath);
            } else {
                SplitApp::logger()->warning("Localhost mode: .split mocks will be deprecated soon in favor of YAML "
                    . "files, which provide more targeting power. Take a look in our documentation.");
                $this->loadSplits($filePath);
            }
        }

        if (is_null($filePath) || $this->splits === null) {
            throw new \Exception("Splits file could not be found");
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param $splitFilePath
     */
    private function loadSplits($splitFilePath)
    {
        $fileContent = file_get_contents($splitFilePath);
        $this->splits = \SplitIO\parseSplitsFile($fileContent);
    }

    /**
     * @param $splitFilePath
     */
    private function loadSplitsFromYAML($splitFilePath)
    {
        $yaml = new Parser();
        $parsed = $yaml->parse(file_get_contents($splitFilePath));

        $splits = array();

        foreach ($parsed as $split) {
            $featureName = key($split);
            $treatment = $split[$featureName]["treatment"];
            if (isset($split[$featureName]["keys"])) {
                $keys = $split[$featureName]["keys"];
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        $splits[$featureName . ":" . $key]["treatment"] = $treatment;
                        if (isset($split[$featureName]["config"])) {
                            $splits[$featureName . ":" . $key]["config"] = $split[$featureName]["config"];
                        }
                    }
                } else {
                    $splits[$featureName . ":" . $keys]["treatment"] = $treatment;
                    if (isset($split[$featureName]["config"])) {
                        $splits[$featureName . ":" . $keys]["config"] = $split[$featureName]["config"];
                    }
                }
            } else {
                $splits[$featureName]["treatment"] = $treatment;
                if (isset($split[$featureName]["config"])) {
                    $splits[$featureName]["config"] = $split[$featureName]["config"];
                }
            }
        }

        $this->splits = $splits;
    }

    public function getSplits()
    {
        return $this->splits;
    }

    public function doValidation($key, $featureName, $operation)
    {
        $key = InputValidator::validateKey($key, $operation);
        if (is_null($key)) {
            return null;
        }

        $featureName = InputValidator::validateFeatureName($featureName, $operation);
        if (is_null($featureName)) {
            return null;
        }

        return is_null($key) ? $featureName : ($featureName . ":" .  $key["matchingKey"]);
    }

    /**
     * @inheritdoc
     */
    public function getTreatment($key, $featureName, array $attributes = null)
    {
        $key = $this->doValidation($key, $featureName, "getTreatment");
        if (is_null($key)) {
            return TreatmentEnum::CONTROL;
        }

        if (isset($this->splits[$key])) {
            return $this->splits[$key]["treatment"];
        } else {
            if (isset($this->splits[$featureName])) {
                return $this->splits[$featureName]["treatment"];
            }
        }

        return TreatmentEnum::CONTROL;
    }

    /**
     * @inheritdoc
     */
    public function getTreatmentWithConfig($key, $featureName, array $attributes = null)
    {
        $treatmentResult = array(
            "treatment" => TreatmentEnum::CONTROL,
            "config" => null,
        );

        $key = $this->doValidation($key, $featureName, "getTreatmentWithConfig");
        if (is_null($key)) {
            return $treatmentResult;
        }

        if (isset($this->splits[$key])) {
            $treatmentResult["treatment"] = $this->splits[$key]["treatment"];
            if (isset($this->splits[$key]["config"])) {
                $treatmentResult["config"] = $this->splits[$key]["config"];
            }
        } else {
            if (isset($this->splits[$featureName])) {
                $treatmentResult["treatment"] = $this->splits[$featureName]["treatment"];
                if (isset($this->splits[$featureName]["config"])) {
                    $treatmentResult["config"] = $this->splits[$featureName]["config"];
                }
            }
        }

        return $treatmentResult;
    }

    /**
     * @inheritdoc
     */
    public function getTreatments($key, $featureNames, array $attributes = null)
    {
        $splitNames = InputValidator::validateFeatureNames($featureNames, "getTreatments");
        if (is_null($splitNames)) {
            return null;
        }

        $key = InputValidator::validateKey($key, "getTreatments");
        if (is_null($key)) {
            return array_fill_keys($splitNames, TreatmentEnum::CONTROL);
        }

        $result = array();

        foreach ($splitNames as $split) {
            $result[$split] = $this->getTreatment($key["matchingKey"], $split, $attributes);
        };

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getTreatmentsWithConfig($key, $featureNames, array $attributes = null)
    {
        $splitNames = InputValidator::validateFeatureNames($featureNames, "getTreatmentsWithConfig");
        if (is_null($splitNames)) {
            return null;
        }

        $key = InputValidator::validateKey($key, "getTreatmentsWithConfig");
        if (is_null($key)) {
            return array_fill_keys($splitNames, array('treatment' => TreatmentEnum::CONTROL, 'config' => null));
        }

        $result = array();

        foreach ($splitNames as $split) {
            $result[$split] = $this->getTreatmentWithConfig($key["matchingKey"], $split, $attributes);
        };

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function isTreatment($key, $featureName, $treatment)
    {
        $calculatedTreatment = $this->getTreatment($key, $featureName);

        if ($calculatedTreatment !== TreatmentEnum::CONTROL) {
            if ($treatment == $calculatedTreatment) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function track($key, $trafficType, $eventType, $value = null)
    {
        return true;
    }
}
