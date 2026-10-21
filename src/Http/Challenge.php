<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Settings;
use ErnestDefoe\Bouncer\Tokens;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\View\Factory;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UriInterface;

/**
 * The challenge page: a few kilobytes of self-contained HTML (no forum
 * assets) that works out a proof of work in the browser, or shows a
 * Turnstile widget, then posts the answer back and returns to the page the
 * visitor asked for.
 */
class Challenge
{
    public function __construct(
        protected Settings $settings,
        protected Tokens $tokens,
        protected UrlGenerator $url,
        protected TranslatorInterface $translator,
        protected Factory $view,
        protected SettingsRepositoryInterface $forumSettings,
    ) {
    }

    public function page(Request $request, string $ip, string $return, bool $failed = false): Response
    {
        $turnstile = $this->settings->turnstile();
        $t = fn (string $key) => $this->translator->trans('ernestdefoe-bouncer.challenge.'.$key);

        $html = $this->view->make('bouncer::challenge', [
            'locale' => $this->translator->getLocale(),
            'forum' => (string) $this->forumSettings->get('forum_title'),
            'heading' => $t('heading'),
            'intro' => $turnstile ? $t('intro_turnstile') : $t('intro'),
            'failedText' => $t('failed'),
            'noscript' => $t('noscript'),
            'footer' => $t('footer'),
            'failed' => $failed,
            'action' => $this->url->to('forum')->route('ernestdefoe-bouncer.verify'),
            'token' => $this->tokens->challenge(ClientIp::network($ip), $request->getHeaderLine('User-Agent'), $return, $turnstile ? 0 : $this->settings->difficulty()),
            'difficulty' => $this->settings->difficulty(),
            'turnstile' => $turnstile['site'] ?? null,
        ])->render();

        return new HtmlResponse($html, 403, [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    /** Where to send the visitor back to: the path and query they asked for. */
    public static function returnPath(Request $request): string
    {
        $uri = $request->getAttribute('originalUri');
        $uri = $uri instanceof UriInterface ? $uri : $request->getUri();
        $query = $uri->getQuery();

        return $uri->getPath().($query !== '' ? '?'.$query : '');
    }
}
