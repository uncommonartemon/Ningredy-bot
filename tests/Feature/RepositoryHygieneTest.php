<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * The repository is public (decision of 2026-11-11). What must not get into it is checked here
 * rather than only asked for in the instructions: nobody's words from a correspondence, nothing
 * that belongs to one machine or one person, and agent instructions that point at nothing.
 */
class RepositoryHygieneTest extends TestCase
{
    public function test_decisions_are_recorded_without_anyone_s_quoted_words(): void
    {
        // A lead-in that names the operator, with or without a date, then a colon and a quotation.
        $speech = '/(?:[Оо]ператор(?:а|ом)?|\b[Oo]perator)(?:,?(?:\s|\*(?!\/)|\/\/)*\d{4}-\d{2}-\d{2}[^:«»\n]{0,70})?\s*:(?:\s|\*(?!\/)|\/\/)*«/u';
        $found = [];
        foreach ($this->files(['app', 'tests', 'database', 'docs', 'resources', 'scripts', 'desktop', '.claude'], ['*.php', '*.md', '*.mjs', '*.js', '*.vue', '*.ps1']) as $file) {
            if ($file->getRealPath() === realpath(__FILE__)) {
                continue;
            }
            $contents = $file->getContents();
            // Most files have no quotation marks at all: the costly look is only for those that do.
            if (str_contains($contents, '«') && preg_match($speech, $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $found[] = $this->relative($file).': '.mb_substr((string) preg_replace('/\s+/u', ' ', substr($contents, $match[0][1], 120)), 0, 90);
            }
        }
        foreach (['PROJECT_STRATEGY.md', 'README.md', 'AGENTS.md'] as $name) {
            if (preg_match($speech, (string) file_get_contents(base_path($name)), $match) === 1) {
                $found[] = $name.': '.mb_substr($match[0], 0, 90);
            }
        }

        $this->assertSame([], $found, 'Write the decision and its date in neutral words; nobody\'s words go into the repository.');
    }

    public function test_agent_files_hold_nothing_that_belongs_to_one_machine_or_person(): void
    {
        $private = [
            'a path of somebody\'s computer' => '/[A-Za-z]:[\\\\\/]Users[\\\\\/]|\/(?:Users|home)\/[a-z][\w.-]+\//',
            'a tunnel address' => '/[a-z0-9-]+\.ngrok(?:-free)?\.(?:app|dev|io)/i',
            'an API key' => '/\bsk-[A-Za-z0-9_-]{20,}/',
            'a Telegram bot token' => '/\b\d{8,10}:[A-Za-z0-9_-]{30,}/',
            'a chat or user id written out' => '/\b(?:BOT_CHAT_ID|BOT_USER_ID|chat_id|telegram_user_id)["\']?\s*(?:=>|=|:)\s*["\']?\d{7,}/',
            'a password written out' => '/\bLOOK_PASS=(?![<.$"\'])\S+|password["\']?\s*(?:=>|:)\s*["\'][^"\'$<]{4,}["\']/i',
        ];
        $found = [];
        $files = iterator_to_array($this->files(['.claude'], ['*']), false);
        $files[] = new SplFileInfo(base_path('AGENTS.md'), '', 'AGENTS.md');
        foreach ($files as $file) {
            foreach ($private as $what => $pattern) {
                if (preg_match($pattern, $file->getContents(), $match) === 1) {
                    $found[] = $this->relative($file).': '.$what.' ('.mb_substr($match[0], 0, 40).')';
                }
            }
        }

        $this->assertSame([], $found, 'Scripts take such values from the environment or from arguments.');
    }

    public function test_every_skill_and_rule_is_well_formed_and_points_at_something_real(): void
    {
        $problems = [];
        foreach (glob(base_path('.claude/skills/*'), GLOB_ONLYDIR) ?: [] as $folder) {
            $name = basename($folder);
            $body = (string) @file_get_contents($folder.'/SKILL.md');
            if (preg_match('/\A---\R(.+?)\R---\R/s', $body, $frontmatter) !== 1) {
                $problems[] = "skill {$name}: no frontmatter";

                continue;
            }
            if (preg_match('/^name:\s*(.+)$/m', $frontmatter[1], $named) !== 1 || trim($named[1]) !== $name) {
                $problems[] = "skill {$name}: its name is not the folder's name";
            }
            if (preg_match('/^description:\s*(.+)$/m', $frontmatter[1], $described) !== 1 || mb_strlen(trim($described[1])) > 1536) {
                $problems[] = "skill {$name}: no description, or one longer than the 1536 characters the listing keeps";
            }
            if (substr_count($body, "\n") > 500) {
                $problems[] = "skill {$name}: longer than 500 lines; move reference material into its own files";
            }
            // A script or reference the skill tells the agent to use has to be there.
            preg_match_all('/\$\{CLAUDE_SKILL_DIR\}\/([\w.\/-]+\.\w+)/', $body, $mentioned);
            foreach (array_unique($mentioned[1]) as $path) {
                if (! is_file($folder.'/'.$path)) {
                    $problems[] = "skill {$name}: names {$path}, which is not in the skill";
                }
            }
        }

        $tracked = array_map(fn (SplFileInfo $file): string => $this->relative($file),
            iterator_to_array($this->files(['app', 'tests', 'database', 'docs', 'resources', 'scripts', '.claude'], ['*']), false));
        $tracked = [...$tracked, 'PROJECT_STRATEGY.md', 'README.md', 'AGENTS.md'];
        foreach (glob(base_path('.claude/rules/*.md')) ?: [] as $rule) {
            $name = basename($rule);
            if (preg_match('/\A---\R(.+?)\R---\R/s', (string) file_get_contents($rule), $frontmatter) !== 1
                || preg_match_all('/^\s*-\s*"([^"]+)"\s*$/m', $frontmatter[1], $paths) < 1) {
                $problems[] = "rule {$name}: no paths; a rule without them loads into every session";

                continue;
            }
            // A rule whose folder was renamed would silently stop loading.
            foreach ($paths[1] as $glob) {
                $pattern = '#^'.strtr(preg_quote($glob, '#'), ['\*\*' => '.*', '\*' => '[^/]*']).'$#';
                if (preg_grep($pattern, $tracked) === []) {
                    $problems[] = "rule {$name}: its path {$glob} matches no file";
                }
            }
        }

        $this->assertSame([], $problems);
        $this->assertNotEmpty(glob(base_path('.claude/skills/*/SKILL.md')));
    }

    /** The file's path from the project root, with forward slashes. */
    private function relative(SplFileInfo $file): string
    {
        return ltrim(str_replace('\\', '/', substr((string) $file->getRealPath(), strlen((string) realpath(base_path())))), '/');
    }

    /**
     * @param  list<string>  $folders
     * @param  list<string>  $names
     * @return iterable<SplFileInfo>
     */
    private function files(array $folders, array $names): iterable
    {
        $folders = array_values(array_filter(array_map(fn (string $folder): string => base_path($folder), $folders), 'is_dir'));

        return Finder::create()->files()->in($folders)->name($names)->ignoreDotFiles(false)->ignoreVCS(true)
            ->exclude(['node_modules', 'vendor'])->size('< 2M');
    }
}
