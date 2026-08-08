import { router } from '@inertiajs/vue3';

/**
 * The token a filter carries once the operator has explicitly cleared it.
 *
 * A missing `filter[...]` key means "not set", and the server answers that with the
 * filter's declared default - which is exactly how the fursuit list keeps opening on
 * Pending (plan 2.3). So "cleared" needs a query-string form of its own, and an empty
 * string cannot be it: Laravel's ConvertEmptyStringsToNull global middleware turns
 * `filter[status]=` back into a missing key before the table ever sees it, and picking
 * "All statuses" would snap straight back to Pending. App\Support\Manage\Table reads the
 * same constant.
 */
export const FILTER_CLEARED = '__none';

/**
 * All list-page state lives in the query string: search, sort, dir, page, per_page and
 * filter[...]. That keeps every view linkable and shareable, and means a poll can reload
 * just the data props without losing where the operator was.
 */
export function useTableQuery(only = ['rows', 'meta', 'filters', 'sort', 'search']) {
  const current = () => Object.fromEntries(new URLSearchParams(window.location.search));

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
    const query = { ...current(), ...params };

    if (resetPage) {
      delete query.page;
    }

    for (const [key, value] of Object.entries(query)) {
      if (value === '' || value === null || value === undefined) {
        delete query[key];
      }
    }

    router.get(window.location.pathname, query, {
      only,
      preserveState: true,
      preserveScroll: true,
      replace,
    });
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

  const setPage = (page) => visit({ page }, { resetPage: false });

  const setPerPage = (perPage) => visit({ per_page: perPage });

  return {
    visit,
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
