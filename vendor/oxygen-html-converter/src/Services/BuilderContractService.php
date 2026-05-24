<?php

namespace OxyHtmlConverter\Services;

/**
 * Validates runtime compatibility contracts for builder element classes.
 */
class BuilderContractService
{
    /**
     * Validate the EssentialElements Button contract used by the converter.
     *
     * @return array{compatible:bool,class:string,issues:array,details:array}
     */
    public function evaluateEssentialButtonContract(): array
    {
        return $this->evaluateElementContract(
            '\\EssentialElements\\Button',
            ['content.content.text', 'content.content.link.url'],
            'oxygen',
            $this->requiredBaseClass()
        );
    }

    /**
     * @return array<string, array{compatible:bool,class:string,issues:array,details:array}>
     */
    public function evaluateEssentialElementContracts(): array
    {
        $contracts = [
            'button' => ['\\EssentialElements\\Button', ['content.content.text', 'content.content.link.url']],
            'heading' => ['\\EssentialElements\\Heading', ['content.content.text']],
            'text' => ['\\EssentialElements\\Text', ['content.content.text']],
            'textLink' => ['\\EssentialElements\\TextLink', ['content.content.text', 'content.content.link.url']],
            'image' => ['\\EssentialElements\\Image2', ['content.image.from', 'content.image.url']],
            'basicList' => ['\\EssentialElements\\BasicList', ['content.content.items[].text']],
            'columns' => ['\\EssentialElements\\Columns', []],
            'column' => ['\\EssentialElements\\Column', []],
            'icon' => ['\\EssentialElements\\Icon', []],
            'formBuilder' => ['\\EssentialElements\\FormBuilder', [
                'content.form.form_name',
                'content.form.fields[].type',
                'content.form.fields[].label',
                'content.form.fields[].advanced.id',
                'content.form.submit_text',
                'content.form.success_message',
                'content.actions.actions',
            ]],
            'loginForm' => ['\\EssentialElements\\LoginForm', ['content.form.submit_text', 'content.form.success_message']],
            'registerForm' => ['\\EssentialElements\\RegisterForm', ['content.form.submit_text', 'content.form.success_message', 'content.form.redirect_url']],
        ];

        $statuses = [];
        foreach ($contracts as $key => [$className, $requiredDynamicPaths]) {
            $statuses[$key] = $this->evaluateElementContract(
                $className,
                $requiredDynamicPaths,
                'oxygen',
                $this->requiredBaseClass()
            );
        }

        return $statuses;
    }

    /**
     * Validate a generic element contract.
     *
     * @param string $className Fully-qualified class name
     * @param array $requiredDynamicPaths Required dynamic property paths
     * @param string|null $requiredAvailability Required availableIn target (e.g. "oxygen")
     * @param string|null $requiredBaseClass Required base class
     * @return array{compatible:bool,class:string,issues:array,details:array}
     */
    public function evaluateElementContract(
        string $className,
        array $requiredDynamicPaths = [],
        ?string $requiredAvailability = null,
        ?string $requiredBaseClass = null
    ): array {
        $normalizedClass = $this->normalizeClassName($className);
        $issues = [];
        $details = [
            'dynamicPaths' => [],
            'defaultPropertiesPaths' => [],
            'requiredContentPaths' => array_values($requiredDynamicPaths),
            'availableIn' => null,
        ];

        if (!class_exists($normalizedClass)) {
            $issues[] = sprintf('Class %s is missing.', $normalizedClass);
            return [
                'compatible' => false,
                'class' => $normalizedClass,
                'issues' => $issues,
                'details' => $details,
            ];
        }

        if ($requiredBaseClass !== null && class_exists($requiredBaseClass)) {
            if (!is_subclass_of($normalizedClass, $requiredBaseClass)) {
                $issues[] = sprintf('Class %s does not extend %s.', $normalizedClass, $requiredBaseClass);
            }
        }

        if (!empty($requiredDynamicPaths)) {
            $defaultProperties = [];
            if (method_exists($normalizedClass, 'defaultProperties')) {
                $defaultPropertiesResult = $this->callStatic($normalizedClass, 'defaultProperties');
                if ($defaultPropertiesResult['ok'] && is_array($defaultPropertiesResult['value'])) {
                    $defaultProperties = $defaultPropertiesResult['value'];
                    $details['defaultPropertiesPaths'] = $this->flattenPropertyPaths($defaultProperties);
                }
            }

            if (!method_exists($normalizedClass, 'dynamicPropertyPaths')) {
                $issues[] = sprintf('Class %s is missing dynamicPropertyPaths().', $normalizedClass);
            } else {
                $dynamicPathsResult = $this->callStatic($normalizedClass, 'dynamicPropertyPaths');
                if ($dynamicPathsResult['ok']) {
                    $dynamicPaths = $this->extractDynamicPaths($dynamicPathsResult['value']);
                    $details['dynamicPaths'] = $dynamicPaths;
                    foreach ($requiredDynamicPaths as $requiredPath) {
                        if (!$this->contractPathSatisfied($requiredPath, $dynamicPaths, $defaultProperties)) {
                            $issues[] = sprintf(
                                'Class %s is missing content contract path "%s".',
                                $normalizedClass,
                                $requiredPath
                            );
                        }
                    }
                } else {
                    $issues[] = sprintf(
                        'Class %s dynamicPropertyPaths() failed: %s',
                        $normalizedClass,
                        $dynamicPathsResult['error']
                    );
                }
            }
        }

        if ($requiredAvailability !== null) {
            if (!method_exists($normalizedClass, 'availableIn')) {
                $issues[] = sprintf('Class %s is missing availableIn().', $normalizedClass);
            } else {
                $availableInResult = $this->callStatic($normalizedClass, 'availableIn');
                if ($availableInResult['ok']) {
                    $availableIn = is_array($availableInResult['value']) ? $availableInResult['value'] : [];
                    $details['availableIn'] = $availableIn;
                    if (!in_array($requiredAvailability, $availableIn, true)) {
                        $issues[] = sprintf(
                            'Class %s is not available in "%s".',
                            $normalizedClass,
                            $requiredAvailability
                        );
                    }
                } else {
                    $issues[] = sprintf(
                        'Class %s availableIn() failed: %s',
                        $normalizedClass,
                        $availableInResult['error']
                    );
                }
            }
        }

        return [
            'compatible' => empty($issues),
            'class' => $normalizedClass,
            'issues' => $issues,
            'details' => $details,
        ];
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function extractDynamicPaths($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = $item['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<int, string> $dynamicPaths
     * @param array<string, mixed> $defaultProperties
     */
    private function contractPathSatisfied(string $requiredPath, array $dynamicPaths, array $defaultProperties): bool
    {
        if (in_array($requiredPath, $dynamicPaths, true)) {
            return true;
        }

        $wildcardlessPath = str_replace('[]', '', $requiredPath);
        if (in_array($wildcardlessPath, $dynamicPaths, true)) {
            return true;
        }

        if ($this->hasPath($defaultProperties, $wildcardlessPath)) {
            return true;
        }

        $segments = explode('.', $wildcardlessPath);
        while (count($segments) > 1) {
            array_pop($segments);
            if ($this->hasPath($defaultProperties, implode('.', $segments))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function hasPath(array $properties, string $path): bool
    {
        $current = $properties;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<int, string>
     */
    private function flattenPropertyPaths(array $properties, string $prefix = ''): array
    {
        $paths = [];
        foreach ($properties as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            $paths[] = $path;
            if (is_array($value)) {
                array_push($paths, ...$this->flattenPropertyPaths($value, $path));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{ok:bool,value:mixed,error:string}
     */
    private function callStatic(string $className, string $method): array
    {
        try {
            if (!is_callable([$className, $method])) {
                return [
                    'ok' => false,
                    'value' => null,
                    'error' => sprintf('%s::%s is not callable', $className, $method),
                ];
            }

            return [
                'ok' => true,
                'value' => call_user_func([$className, $method]),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'value' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizeClassName(string $className): string
    {
        return '\\' . ltrim($className, '\\');
    }

    private function requiredBaseClass(): ?string
    {
        return class_exists('\\Breakdance\\Elements\\Element')
            ? '\\Breakdance\\Elements\\Element'
            : null;
    }
}
