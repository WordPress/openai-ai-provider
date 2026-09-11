<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

class OpenAiProviderTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProviderLogoUsesWordPressPluginDirectory(): void
    {
        $pluginDir = '/wordpress/wp-content/plugins/ai-provider-for-openai';
        define('AI_PROVIDER_FOR_OPENAI_PLUGIN_DIR', $pluginDir);

        $metadata = OpenAiProvider::metadata();

        $this->assertSame(
            $pluginDir . '/assets/images/openai.svg',
            $metadata->getLogoPath()
        );
    }
}
