<?php

namespace App\Services\Studio;

use App\Models\ProjectTrack;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Mixes a track in the studio itself, in headless Chrome, for mixes nobody
 * has the studio open for. Chrome opens the app's render page with signed
 * links to read the track and upload the mix, and waits for it to finish.
 */
class BrowserMix
{
    /** The page finished and uploaded the mix */
    public const DONE = 'done';

    /** The track has nothing to mix */
    public const EMPTY = 'empty';

    /**
     * Mix the track for mix request `$id`. Returns DONE or EMPTY, and throws
     * when the page fails or runs out of time.
     */
    public function render(ProjectTrack $track, string $id): string
    {
        $timeout = max(30, (int) config('studio.render.timeout'));
        $browser = (new BrowserFactory(config('studio.render.chrome')))
            ->createBrowser([
                'headless' => true,
                'noSandbox' => true,
                'windowSize' => [1280, 800],
                'customFlags' => [
                    '--autoplay-policy=no-user-gesture-required',
                    '--disable-dev-shm-usage',
                    '--mute-audio',
                    '--no-first-run',
                    '--disable-background-networking',
                    '--disable-component-update',
                    '--disable-sync',
                ],
            ]);

        try {
            $page = $browser->createPage();

            $page->navigate($this->pageUrl($track, $id, $timeout))
                ->waitForNavigation(Page::LOAD, 60000);

            return $this->wait($page, $timeout);
        } finally {
            $browser->close();
        }
    }

    /**
     * The render page, with signed links to the track's data and to upload
     * the mix. The links last as long as a render may.
     */
    public function pageUrl(ProjectTrack $track, string $id, int $timeout): string
    {
        $until = now()->addSeconds($timeout + 60);
        $data = URL::temporarySignedRoute('studio.render.show', $until, ['track' => $track->id]);
        $upload = URL::temporarySignedRoute('studio.render.store', $until, ['track' => $track->id, 'mix' => $id]);
        $base = config('studio.render.url') ?: rtrim((string) config('app.frontend_url'), '/') . '/studio/render';

        return $base . '?' . http_build_query(['data' => $data, 'upload' => $upload]);
    }

    /**
     * Wait for the page to say how the mix went, in `window.tfMix`.
     */
    protected function wait(Page $page, int $timeout): string
    {
        $until = microtime(true) + $timeout;

        while (microtime(true) < $until) {
            $state = json_decode((string) $page
                ->evaluate('JSON.stringify(window.tfMix ?? null)')
                ->getReturnValue(), true);
            $status = is_array($state) ? ($state['status'] ?? null) : null;

            if (in_array($status, [self::DONE, self::EMPTY], true)) {
                return $status;
            }

            if ($status === 'failed') {
                throw new RuntimeException('The studio couldn\'t mix the track: ' . ($state['error'] ?? 'unknown error'));
            }

            usleep(500000);
        }

        throw new RuntimeException('The studio took too long to mix the track.');
    }
}
