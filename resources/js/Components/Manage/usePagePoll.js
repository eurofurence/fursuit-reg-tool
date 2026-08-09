import { onScopeDispose } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * A polling partial reload that cannot undo what the operator just did.
 *
 * Inertia's own `usePoll` is `router.reload()`, which is an *async* visit against
 * `window.location.href` as it was when the timer fired. Nothing coordinates it with the
 * visits the operator causes: `router.visit` only cancels in-flight async requests when
 * the new visit lands on a different path, and every list-page visit - filter, search,
 * sort, page - is a partial reload of the same path with a different query string. So the
 * poll survives it, and when its response arrives Inertia applies it in full: the props it
 * asked for *and* the URL it was sent to.
 *
 * That is the "the filter undoes itself" bug, and it needs no bad network to happen, only
 * a poll that overlaps a visit:
 *
 *   t+0.0s  poll fires against /admin/badges
 *   t+0.3s  operator types a search; visit goes out to /admin/badges?search=andreas
 *   t+0.9s  the visit lands: filtered rows, address bar shows ?search=andreas
 *   t+1.2s  the *poll* lands: unfiltered rows, address bar back to /admin/badges
 *
 * The search box still says "andreas" - `search` is not one of the polled keys, so nothing
 * replaced it - which is what makes it read as a glitch rather than as a reload: the
 * controls say one thing and the rows say another, and the next tick keeps it that way
 * because the URL it now polls is the unfiltered one.
 *
 * Two rules fix it, and both are about the same thing - a poll may only ever speak for the
 * view that is currently on screen:
 *
 *  - a tick is skipped while an operator-initiated (sync) visit is in flight, because the
 *    URL it would poll is the one being navigated away from;
 *  - an in-flight poll is cancelled the moment such a visit starts, because its answer is
 *    about to be about the wrong URL.
 *
 * Ticks are also skipped while the tab is hidden, which is `usePoll`'s own default
 * (`keepAlive: false`) and the reason a backgrounded panel does not keep hitting the
 * server.
 *
 * @param {number} interval  milliseconds between ticks
 * @param {object} options   Inertia visit options, principally `only`
 */
export function usePagePoll(interval, options = {}) {
  /** Cancels the poll request that is in flight, if there is one. */
  let cancel = null;

  /** How many operator-initiated visits are in flight. */
  let navigating = 0;

  const cancelInFlight = () => {
    cancel?.();
    cancel = null;
  };

  const tick = () => {
    if (navigating > 0 || cancel !== null || document.hidden) {
      return;
    }

    router.reload({
      ...options,
      onCancelToken: (token) => {
        cancel = token.cancel;
      },
      onFinish: () => {
        cancel = null;
      },
    });
  };

  const timer = window.setInterval(tick, interval);

  /*
   * `visit.async` is what separates the two kinds: every poll and prefetch is async, and
   * everything the operator causes - a link, a filter, a bulk action - is not. Prefetches
   * are async too and are deliberately left alone; they write no props and no URL.
   */
  const started = router.on('start', (event) => {
    if (event.detail.visit.async) {
      return;
    }

    navigating += 1;
    cancelInFlight();
  });

  const finished = router.on('finish', (event) => {
    if (event.detail.visit.async) {
      return;
    }

    navigating = Math.max(0, navigating - 1);
  });

  onScopeDispose(() => {
    window.clearInterval(timer);
    cancelInFlight();
    started();
    finished();
  });
}
