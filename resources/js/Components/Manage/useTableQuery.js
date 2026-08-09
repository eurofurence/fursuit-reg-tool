import { router } from '@inertiajs/vue3';

/**
 * The token a filter carries once the operator has explicitly cleared it.
 *
 * A missing `filter[...]` key means "not set", and the server answers that with the
 * filter's declared default - which is exactly how the fursuit list keeps opening on
 * Pending. So "cleared" needs a query-string form of its own, and an empty
 * string cannot be it: Laravel's ConvertEmptyStringsToNull global middleware turns
 * `filter[status]=` back into a missing key before the table ever sees it, and picking
 * "All statuses" would snap straight back to Pending. App\Support\Manage\Table reads the
 * same constant.
 */
export const FILTER_CLEARED = '__none';

/**
 * Which preset view a URL is showing.
 *
 * Mirrors App\Support\Manage\Table::resolveTab, and has to keep mirroring it: a missing
 * `tab` key is the first declared tab, and so is a key that matches nothing, so that a
 * stale or hand-edited link highlights the same tab whose rows the server sent back.
 *
 * Takes the URL rather than reading `window.location`, because both callers have the
 * reactive one from `usePage()` and a strip that only updated on a full page load would
 * freeze on the first tab the moment anyone switched.
 */
export function activeTabKey(tabs, url) {
  const requested = new URLSearchParams(String(url).split('?')[1] ?? '').get('tab');

  return tabs.some((tab) => tab.key === requested) ? requested : (tabs[0]?.key ?? null);
}

/**
 * All list-page state lives in the query string: tab, search, sort, dir, page, per_page and
 * filter[...]. That keeps every view linkable and shareable, and means a poll can reload
 * just the data props without losing where the operator was.
 */
export function useTableQuery(only = ['rows', 'meta', 'filters', 'sort', 'search']) {
  const current = () => Object.fromEntries(new URLSearchParams(window.location.search));

  /**
   * The query a visit would send, with `null` and '' removed.
   *
   * Split out of `visit` so a control that is a link as well as a click - the tab strip,
   * whose anchors have to carry a real href for middle-click and copy-link - builds its
   * href from the same merge that its click performs. Two code paths writing the same URL
   * is how they end up disagreeing.
   */
  const merge = (params, { resetPage = true } = {}) => {
    const query = { ...current(), ...params };

    if (resetPage) {
      delete query.page;
    }

    for (const [key, value] of Object.entries(query)) {
      if (value === '' || value === null || value === undefined) {
        delete query[key];
      }
    }

    return query;
  };

  /**
   * `null` in `params` removes a key. An empty string is dropped too, which is right for
   * search, sort and page - none of them has a server-side default to fall back on - but
   * a filter never sends one; it sends FILTER_CLEARED instead.
   *
   * `replace` decides whether the visit overwrites the current history entry or pushes a
   * new one. Everything that fires while the operator is still deciding - a keystroke in
   * the search box, a page step - replaces, so Back is not a hundred presses deep.
   * Applying, changing or removing a filter pushes, because the filtered view is the
   * thing an operator navigates between and expects Back to undo.
   */
  const visit = (params, { resetPage = true, replace = true } = {}) => {
    router.get(window.location.pathname, merge(params, { resetPage }), {
      only,
      preserveState: true,
      preserveScroll: true,
      replace,
    });
  };

  const urlFor = (params, options = {}) => {
    const query = new URLSearchParams(merge(params, options)).toString();

    return query ? `${window.location.pathname}?${query}` : window.location.pathname;
  };

  const toggleSort = (column, sort) => {
    if (!column.sortable) {
      return;
    }

    const dir = sort?.key === column.key && sort?.dir === 'asc' ? 'desc' : 'asc';

    visit({ sort: column.key, dir });
  };

  const setSearch = (value) => visit({ search: value });

  /**
   * Every existing query key belonging to this filter, scalar or indexed, written as
   * null so `visit` removes it. Written as null rather than '' because '' is now
   * indistinguishable from a value the operator typed.
   *
   * Clearing first is what lets a three-value multi-select become a one-value one
   * without `filter[status][1]` and `[2]` surviving underneath it.
   */
  const dropKeys = (key) => {
    const params = {};

    for (const existing of Object.keys(current())) {
      if (existing === `filter[${key}]` || existing.startsWith(`filter[${key}][`)) {
        params[existing] = null;
      }
    }

    return params;
  };

  /**
   * Whether this filter is written into the URL at all, in any of its forms.
   *
   * The bar needs it to know whether removing a chip has anything to undo. An emptied
   * multi-select sits in the URL as FILTER_CLEARED while its chip is still on the bar,
   * and taking that chip off has to take the token with it or the next operator inherits
   * a cleared filter nobody can see.
   */
  const hasFilterParam = (key) =>
    Object.keys(current()).some(
      (existing) => existing === `filter[${key}]` || existing.startsWith(`filter[${key}][`),
    );

  const setFilter = (key, value) => {
    const params = dropKeys(key);

    if (Array.isArray(value)) {
      if (value.length === 0) {
        params[`filter[${key}]`] = FILTER_CLEARED;
      } else {
        value.forEach((item, index) => {
          params[`filter[${key}][${index}]`] = item;
        });
      }

      return visit(params, { replace: false });
    }

    // A range arrives as { min, max }. A blank bound is left out of the URL entirely
    // rather than sent empty: the pair is one filter, and half a range is a legitimate
    // half-set filter, not a cleared one.
    if (value !== null && typeof value === 'object') {
      const parts = Object.entries(value).filter(([, part]) => part !== '' && part != null);

      if (parts.length === 0) {
        params[`filter[${key}]`] = FILTER_CLEARED;
      } else {
        for (const [part, bound] of parts) {
          params[`filter[${key}][${part}]`] = bound;
        }
      }

      return visit(params, { replace: false });
    }

    if (typeof value === 'boolean') {
      params[`filter[${key}]`] = value ? '1' : '0';
    } else {
      params[`filter[${key}]`] = value === '' ? FILTER_CLEARED : value;
    }

    return visit(params, { replace: false });
  };

  /**
   * Takes a filter off the bar entirely.
   *
   * Two different URLs, and which one is right depends on the declaration. A filter the
   * module gave a default has to leave FILTER_CLEARED behind, or the absent key means
   * "not set" and the server re-applies the default on the very next response. A filter
   * with no default is removed by dropping its keys, which is what keeps an unapplied
   * filter genuinely absent from the query string instead of parked there as a token
   * nobody can read.
   *
   * `App\Support\Manage\Filter::toArray` sends `default` so the caller can tell the two
   * apart; see hasDeclaredDefault in filterValue.js.
   *
   * Several at once go in one visit rather than one each: "clear all" on a bar of five
   * chips is one thing the operator did, so it is one request and one history entry.
   */
  const clearFilters = (entries) => {
    const params = {};

    for (const { key, defaulted = false } of entries) {
      Object.assign(params, dropKeys(key));

      if (defaulted) {
        params[`filter[${key}]`] = FILTER_CLEARED;
      }
    }

    return visit(params, { replace: false });
  };

  const clearFilter = (key, options = {}) => clearFilters([{ key, ...options }]);

  /**
   * Switching the preset view.
   *
   * `param` is null for the module's default tab, which is written into the URL by the
   * absence of the key so the canonical link to the unnarrowed list stays the bare URL.
   * App\Support\Manage\Table reads a missing `tab` as the first declared one.
   *
   * What moves and what does not. The page is dropped, because page 7 of one view is not
   * a place in another and landing on an empty page 7 looks like the tab is broken.
   * Chip filters, sort and search are all kept, deliberately: the tab is the view and the
   * chips narrow within it, so flipping the view an operator is refining has to keep the
   * refinement, and the two exist precisely so a slice can be compared across views. It
   * is also the recoverable choice - a chip that is now unwanted is one click from gone,
   * whereas a chip set discarded on a mistaken tab click has to be retyped - and the
   * chips stay visible on the row below the strip, so an empty result explains itself.
   * Resetting them would also be a lie for any filter with a declared default, which
   * cannot be reset to nothing; it snaps back to its default.
   *
   * Pushes rather than replaces, for the same reason a filter change does: the view is
   * the thing an operator navigates between and expects Back to undo.
   */
  const setTab = (param) => visit({ tab: param }, { replace: false });

  const tabUrl = (param) => urlFor({ tab: param });

  const setPage = (page) => visit({ page }, { resetPage: false });

  const setPerPage = (perPage) => visit({ per_page: perPage });

  return {
    visit,
    urlFor,
    setTab,
    tabUrl,
    toggleSort,
    setSearch,
    setFilter,
    clearFilter,
    clearFilters,
    hasFilterParam,
    setPage,
    setPerPage,
  };
}
