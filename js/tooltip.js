/**
 * Immediate tooltip for the Panel: v-ftp-backup-tooltip="text"
 * Works on elements and on components such as k-button, an empty value shows nothing.
 * The Panel has no tooltip of its own and the browser title comes late and unstyled.
 *
 * One element on the body serves all triggers, so no overflow container clips it.
 * The surface is styled in tooltip.css.
 */

// distance to the trigger and to the window edge in px
const GAP = 6;
const EDGE = 8;

const TOOLTIP_ID = 'k-ftp-backup-tooltip';

let tooltip = null;
let activeTrigger = null;

/**
 * Where to put the tooltip: under the trigger on its left edge, right-aligned
 * near the right window edge, above the trigger near the bottom window edge.
 *
 * @param {{top: number, right: number, bottom: number, left: number}} trigger
 * @param {{width: number, height: number}} size of the tooltip
 * @param {{width: number, height: number}} viewport
 * @returns {{top: number, left: number}}
 */
export function tooltipPosition(trigger, size, viewport) {
  const maxLeft = viewport.width - EDGE - size.width;
  const maxTop = viewport.height - EDGE - size.height;

  let left = trigger.left;

  if (left > maxLeft) {
    left = trigger.right - size.width;
  }

  let top = trigger.bottom + GAP;
  const above = trigger.top - GAP - size.height;

  if (top > maxTop && above >= EDGE) {
    top = above;
  }

  // the window edge always wins
  return {
    top: Math.max(EDGE, Math.min(top, maxTop)),
    left: Math.max(EDGE, Math.min(left, maxLeft))
  };
}

function tooltipElement() {
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = TOOLTIP_ID;
    tooltip.className = 'k-ftp-backup-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;

    // dialogs and drawers sit in the browser's top layer, only a popover gets above them
    if (typeof tooltip.showPopover === 'function') {
      tooltip.setAttribute('popover', 'manual');
    }

    document.body.appendChild(tooltip);
  }

  return tooltip;
}

function show(trigger) {
  hide();

  const text = trigger._ftpBackupTooltip.text;

  if (!text) {
    return;
  }

  const element = tooltipElement();
  element.textContent = text;
  element.hidden = false;

  if (element.hasAttribute('popover')) {
    element.showPopover();
  }

  // measure with the text in place, wrapping decides the height
  const {top, left} = tooltipPosition(
    trigger.getBoundingClientRect(),
    element.getBoundingClientRect(),
    {width: document.documentElement.clientWidth, height: document.documentElement.clientHeight}
  );

  element.style.top = top + 'px';
  element.style.left = left + 'px';

  trigger.setAttribute('aria-describedby', TOOLTIP_ID);
  activeTrigger = trigger;

  // a fixed tooltip does not follow its trigger
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  document.addEventListener('keydown', onKeydown);
}

function hide() {
  if (!activeTrigger) {
    return;
  }

  if (tooltip.hasAttribute('popover') && tooltip.matches(':popover-open')) {
    tooltip.hidePopover();
  }

  tooltip.hidden = true;
  activeTrigger.removeAttribute('aria-describedby');
  activeTrigger = null;

  window.removeEventListener('scroll', hide, true);
  window.removeEventListener('resize', hide);
  document.removeEventListener('keydown', onKeydown);
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    hide();
  }
}

function onPointerEnter(event) {
  // no hover on touch, a tap only triggers the element
  if (event.pointerType !== 'touch') {
    show(event.currentTarget);
  }
}

function onFocus(event) {
  // keyboard only, a click must not bring back the tooltip it just closed
  if (event.currentTarget.matches(':focus-visible')) {
    show(event.currentTarget);
  }
}

/**
 * Keeps a trigger usable without a mouse and without the title attribute:
 * icon-only triggers without an aria-label of their own get the text as name.
 */
function update(trigger, text) {
  const state = trigger._ftpBackupTooltip;
  state.text = text;

  if (state.named || (!trigger.hasAttribute('aria-label') && !trigger.textContent.trim())) {
    state.named = true;

    if (text) {
      trigger.setAttribute('aria-label', text);
    } else {
      trigger.removeAttribute('aria-label');
    }
  }

  // text and icons need a tab stop to show the tooltip on keyboard focus
  if (state.tabStop || !trigger.matches('a[href], button, input, select, textarea, [tabindex]')) {
    state.tabStop = true;

    if (text) {
      trigger.setAttribute('tabindex', '0');
    } else {
      trigger.removeAttribute('tabindex');
    }
  }
}

// Vue 3 directive hooks; on a component such as k-button they act on its root element
const directive = {
  mounted(el, binding) {
    el._ftpBackupTooltip = {text: null, named: false, tabStop: false};
    update(el, binding.value);

    el.addEventListener('pointerenter', onPointerEnter);
    el.addEventListener('pointerleave', hide);
    el.addEventListener('focus', onFocus);
    el.addEventListener('blur', hide);
    el.addEventListener('click', hide);
  },

  updated(el, binding) {
    if (binding.value === binding.oldValue) {
      return;
    }

    update(el, binding.value);

    if (activeTrigger === el) {
      show(el);
    }
  },

  beforeUnmount(el) {
    if (activeTrigger === el) {
      hide();
    }

    el.removeEventListener('pointerenter', onPointerEnter);
    el.removeEventListener('pointerleave', hide);
    el.removeEventListener('focus', onFocus);
    el.removeEventListener('blur', hide);
    el.removeEventListener('click', hide);
  }
};

export default {
  install(app) {
    app.directive('ftp-backup-tooltip', directive);
  }
};
