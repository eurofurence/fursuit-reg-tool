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
   */
  const visit = (params, { resetPage = true } = {}) => {
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
      replace: true,
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

  const setFilter = (key, value) => {
    const params = {};

    // Drop any previous entry for this filter, indexed or scalar, before writing the
    // new one. Written as null so `visit` removes them rather than as '', which would
    // now be indistinguishable from a value.
    for (const existing of Object.keys(current())) {
      if (existing === `filter[${key}]` || existing.startsWith(`filter[${key}][`)) {
        params[existing] = null;
      }
    }

    if (Array.isArray(value)) {
      if (value.length === 0) {
        params[`filter[${key}]`] = FILTER_CLEARED;
      } else {
        value.forEach((item, index) => {
          params[`filter[${key}][${index}]`] = item;
        });
      }

      return visit(params);
    }

    if (typeof value === 'boolean') {
      params[`filter[${key}]`] = value ? '1' : '0';
    } else {
      params[`filter[${key}]`] = value === '' ? FILTER_CLEARED : value;
    }

    return visit(params);
  };

  const setPage = (page) => visit({ page }, { resetPage: false });

  const setPerPage = (perPage) => visit({ per_page: perPage });

  return { visit, toggleSort, setSearch, setFilter, setPage, setPerPage };
}
