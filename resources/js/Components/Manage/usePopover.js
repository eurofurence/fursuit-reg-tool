import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * The dismissal and focus mechanics EventSelector works out for the event listbox, moved
 * into one place so the filter bar's several popovers behave identically to it rather
 * than each inventing their own.
 *
 * Same three rules, and they are the ones worth stating:
 *
 *  - Escape and choosing hand focus back to the trigger. Dismissing by clicking or
 *    tabbing away must not, or focus is yanked back from wherever the operator went.
 *  - Outside pointerdown is listened for in the capture phase, so one click both closes
 *    this popover and still reaches the control it landed on.
 *  - focusout on the root covers Tab out. Tab itself is never intercepted; the popover
 *    just stops existing behind the operator.
 *
 * The one thing this does not do is trap focus. These panels are small, they sit next to
 * their trigger in the DOM, and a trap would be a second modal contract to maintain
 * beside ManageDialog's.
 */
export function usePopover({ panelWidth = 0 } = {}) {
  const open = ref(false);
  const root = ref(null);
  const trigger = ref(null);

  /**
   * Runs after the panel is in the DOM, so a caller can move focus into it.
   *
   * @type {import('vue').Ref<null | (() => void)>}
   */
  const onOpened = ref(null);

  /**
   * Which edge the panel hangs from.
   *
   * A panel anchored left of a chip that is already near the right edge would push the
   * document wider and give the whole page a horizontal scrollbar, which is exactly what
   * a bar of wrapping chips must never do. Measured against the trigger's own position
   * each time it opens, because chips move as others are added and removed.
   */
  const alignRight = ref(false);

  const openPopover = async () => {
    if (open.value) {
      return;
    }

    const rect = root.value?.getBoundingClientRect();

    alignRight.value = Boolean(rect) && rect.left + panelWidth > window.innerWidth - 8;

    open.value = true;

    await nextTick();
    onOpened.value?.();
  };

  const close = ({ refocus = true } = {}) => {
    if (!open.value) {
      return;
    }

    open.value = false;

    if (refocus) {
      nextTick(() => trigger.value?.focus());
    }
  };

  const toggle = () => (open.value ? close() : openPopover());

  const onFocusOut = (event) => {
    if (!root.value?.contains(event.relatedTarget)) {
      close({ refocus: false });
    }
  };

  const onPointerDown = (event) => {
    if (!root.value?.contains(event.target)) {
      close({ refocus: false });
    }
  };

  watch(open, (isOpen) => {
    if (isOpen) {
      document.addEventListener('pointerdown', onPointerDown, true);
    } else {
      document.removeEventListener('pointerdown', onPointerDown, true);
    }
  });

  onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown, true));

  return { open, root, trigger, openPopover, close, toggle, onFocusOut, onOpened, alignRight };
}

/**
 * Arrow / Home / End over a list of real focusable elements, for the menus where focus
 * moves rather than aria-activedescendant riding along. Clamped rather than wrapping,
 * matching EventSelector: Home and End are the ways to reach the ends.
 */
export function focusItem(items, index) {
  const list = items.filter(Boolean);

  if (list.length === 0) {
    return;
  }

  const clamped = Math.min(list.length - 1, Math.max(0, index));

  list[clamped].focus();
  list[clamped].scrollIntoView({ block: 'nearest' });
}
