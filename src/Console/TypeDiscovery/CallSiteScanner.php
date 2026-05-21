<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console\TypeDiscovery;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Walk a host's source tree and find every place that calls the package's
 * NotificationDispatcher::send() / ::schedule().
 *
 * Two passes:
 *
 *   1. Cheap text filter — read each .php file's contents and keep only
 *      those that contain the substring "NotificationDispatcher". A typical
 *      Laravel app's `app/` directory has hundreds of files, most of which
 *      have no notification triggers; running the AST parser on all of them
 *      is wasted work. This filter shrinks the per-file parse set to the
 *      handful of services / controllers / observers that actually emit.
 *
 *   2. AST walk on the survivors. The {@see CallSiteVisitor} identifies
 *      dispatcher properties and matching method calls, capturing the
 *      type key (first arg, if a string literal), the context array's
 *      string keys (second arg, if an array literal), and the source
 *      file:line.
 *
 * Hosts that alias the dispatcher (`use … as Notifier;`) are handled by
 * the NameResolver visitor, which rewrites every Name node to its FQCN
 * before {@see CallSiteVisitor} runs — `Notifier::class` resolves to the
 * dispatcher's real FQCN and matches normally.
 */
class CallSiteScanner
{
    protected Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @return array<int, DiscoveredCallSite>
     */
    public function scan(string $baseDir): array
    {
        if (! is_dir($baseDir)) {
            return [];
        }

        $found = [];

        foreach ($this->candidateFiles($baseDir) as $file) {
            foreach ($this->scanFile($file) as $callSite) {
                $found[] = $callSite;
            }
        }

        return $found;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    protected function candidateFiles(string $baseDir): iterable
    {
        $finder = (new Finder)
            ->files()
            ->name('*.php')
            ->in($baseDir)
            ->ignoreUnreadableDirs()
            ->ignoreDotFiles(true);

        foreach ($finder as $file) {
            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            // The text filter looks for the short class name, which is
            // robust to both `use Devletes\…\NotificationDispatcher;` imports
            // and fully-qualified references. An aliased import
            // (`use … as Notifier`) wouldn't contain the short name in the
            // call site itself, but the `use` line still does — the substring
            // matches and the AST pass resolves the alias correctly.
            if (! str_contains($contents, 'NotificationDispatcher')) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * @return array<int, DiscoveredCallSite>
     */
    protected function scanFile(SplFileInfo $file): array
    {
        $contents = @file_get_contents($file->getPathname());

        if ($contents === false || $contents === '') {
            return [];
        }

        // Strip a leading UTF-8 BOM so files saved by Windows editors
        // (Notepad, PowerShell `Out-File -Encoding utf8`) parse as if the
        // `<?php` is on line 1 — otherwise the parser sees the BOM as
        // pre-namespace content and rejects every namespace declaration.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        try {
            $ast = $this->parser->parse($contents);
        } catch (\Throwable) {
            // Unparseable file (syntax error in host code). Skip silently
            // — surfacing it as a command warning would just create noise
            // for files that already fail PHP-CS / IDE lint.
            return [];
        }

        if ($ast === null) {
            return [];
        }

        // NameResolver and CallSiteVisitor run in SEPARATE traversals.
        // The visitor inspects child nodes of a MethodCall (the receiver,
        // its args, the args' ClassConstFetch) at enterNode time, but
        // NodeTraverser is depth-first — when a single combined pass
        // enters MethodCall, its children haven't been visited yet, so
        // NameResolver hasn't had a chance to rewrite `Foo::class` into
        // its FQCN. Running NameResolver first as a complete pass ensures
        // the whole tree is resolved by the time our visitor sees it.
        $resolverTraverser = new NodeTraverser;
        $resolverTraverser->addVisitor(new NameResolver);
        $ast = $resolverTraverser->traverse($ast);

        $myTraverser = new NodeTraverser;
        $visitor = new CallSiteVisitor($file->getPathname());
        $myTraverser->addVisitor($visitor);
        $myTraverser->traverse($ast);

        return $visitor->found;
    }
}
