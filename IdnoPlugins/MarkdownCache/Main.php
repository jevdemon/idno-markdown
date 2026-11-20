<?php

namespace IdnoPlugins\MarkdownCache {

    class Main extends \Idno\Common\Plugin
    {
        function registerEventHooks()
        {
            // Idno fires 'saved' for a new post, 'updated' for an edit and 'deleted' after a delete.
            foreach (['saved', 'updated', 'deleted'] as $eventName) {
                \Idno\Core\Idno::site()->events()->addListener($eventName, function (\Idno\Core\Event $event) {
                    self::clearCaches();
                });
            }
        }

        static function clearCaches()
        {
            $gfx = \Idno\Core\Idno::site()->config()->path . '/gfx';

            // md.php: one cached file per post, plus "not found" markers
            foreach (array_merge(glob($gfx . '/md-cache/*.md') ?: [], glob($gfx . '/md-cache/*.404') ?: []) as $file) {
                @unlink($file);
            }

            // llms.php (optional): the single llms.txt cache
            @unlink($gfx . '/llms-cache.txt');
        }
    }

}
