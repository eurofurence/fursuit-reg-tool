/**
 * The client half of App\Support\Manage\Filter's value semantics.
 *
 * The bar has to answer three questions the server already answers for itself, and it
 * cannot ask: is this filter narrowing anything (so does it get a chip), does it carry a
 * declared default (so how is it removed), and what does its value read as on the pill.
 * Getting the first two wrong is not a cosmetic bug - a filter judged inactive loses its
 * chip and the operator has no way back to it, and a defaulted filter removed by dropping
 * its key comes straight back on the next paint.
 *
 * So this file is a deliberate mirror of Filter::isActive() and Filter::emptyValue(), and
 * the two have to be changed together. It is a mirror rather than a server-sent flag
 * because the bar edits values locally before any of them reach the server.
 */

/**
 * Whether this value narrows the query at all. Mirrors Filter::isActive().
 */
export function isActive(filter, value = filter.value) {
  switch (filter.type) {
    case 'boolean':
      return Boolean(value);
    case 'select':
      return filter.multiple ? Array.isArray(value) && value.length > 0 : value !== '' && value != null;
    case 'ternary':
      return value === '1' || value === '0' || value === true || value === false;
    case 'range':
      return (value?.min ?? '') !== '' || (value?.max ?? '') !== '';
    default:
      return value !== '' && value != null;
  }
}

/**
 * The blank value for this filter's type, ignoring any declared default. Mirrors
 * Filter::emptyValue(), and is what a chip's Clear writes into its own editor.
 */
export function emptyValue(filter) {
  switch (filter.type) {
    case 'boolean':
      return false;
    case 'select':
      return filter.multiple ? [] : '';
    case 'range':
      return { min: '', max: '' };
    default:
      return '';
  }
}

/**
 * Whether the module declared a default that is itself active.
 *
 * This is the whole reason `default` is in the envelope. Removing such a filter has to
 * send Filter::CLEARED, because dropping the key means "not set" and the server answers
 * that with the default again - the fursuit list snapping back to Pending. Removing any
 * other filter drops the key, which is what keeps an unapplied filter genuinely absent
 * from the URL rather than parked there as a token.
 */
export function hasDeclaredDefault(filter) {
  return isActive(filter, filter.default);
}

/** The label an option value carries, falling back to the raw value. */
export function optionLabel(filter, value) {
  return filter.options?.find((option) => String(option.value) === String(value))?.label ?? String(value);
}

/**
 * What the chip reads to the right of its label. Null when the filter is on the bar but
 * not yet narrowing anything, which is the state a freshly added chip opens in.
 */
export function summarize(filter, value = filter.value) {
  if (!isActive(filter, value)) {
    return null;
  }

  switch (filter.type) {
    case 'boolean':
      return filter.trueLabel ?? 'Yes';
    case 'ternary':
      return String(value) === '1' ? (filter.trueLabel ?? 'Yes') : (filter.falseLabel ?? 'No');
    case 'select':
      if (!filter.multiple) {
        return optionLabel(filter, value);
      }

      // One choice reads as itself; past that the pill would grow without limit, and the
      // full set is one click away inside the chip's own popover.
      return value.length === 1 ? optionLabel(filter, value[0]) : `${value.length} selected`;
    case 'range': {
      const min = value?.min ?? '';
      const max = value?.max ?? '';

      if (min !== '' && max !== '') {
        return `${min} to ${max}`;
      }

      return min !== '' ? `from ${min}` : `up to ${max}`;
    }
    default:
      return String(value);
  }
}
