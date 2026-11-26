import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'

// The smallest DOM the tooltip needs: elements with attributes, listeners and a box
class FakeElement {
  constructor (tag) {
    this.tag = tag
    this.attributes = {}
    this.listeners = {}
    this.children = []
    this.style = {}
    this.hidden = false
    this.textContent = ''
  }

  setAttribute (name, value) { this.attributes[name] = String(value) }
  getAttribute (name) { return this.attributes[name] ?? null }
  hasAttribute (name) { return name in this.attributes }
  removeAttribute (name) { delete this.attributes[name] }
  addEventListener (type, fn) { (this.listeners[type] ??= []).push(fn) }
  removeEventListener (type, fn) { this.listeners[type] = (this.listeners[type] ?? []).filter(f => f !== fn) }
  appendChild (child) { this.children.push(child) }
  contains (other) { return other === this }
  getBoundingClientRect () { return { top: 10, right: 60, bottom: 30, left: 10, width: 50, height: 20 } }

  // a button, so the tooltip neither adds a tab stop nor an aria-label;
  // focus counts as keyboard focus
  matches (selector) { return selector.includes('button') || selector === ':focus-visible' }

  dispatch (type, props = {}) {
    for (const fn of this.listeners[type] ?? []) {
      fn({ type, currentTarget: this, pointerType: 'mouse', relatedTarget: null, ...props })
    }
  }
}

const body = new FakeElement('body')
globalThis.document = {
  body,
  documentElement: { clientWidth: 1000, clientHeight: 800 },
  createElement: tag => new FakeElement(tag)
}
globalThis.window = { addEventListener () {}, removeEventListener () {} }

const { default: plugin } = await import('../../js/tooltip.js')

let directive
plugin.install({ directive: (name, value) => { directive = value } })

function trigger (text) {
  const el = new FakeElement('button')
  directive.mounted(el, { value: text })
  return el
}

function setText (el, value, oldValue) {
  directive.updated(el, { value, oldValue })
}

const bubble = () => body.children[0]
const visible = () => Boolean(bubble()) && !bubble().hidden

beforeEach(() => {
  // the shared tooltip: close what the previous test left open
  if (bubble()) {
    bubble().hidden = true
  }
})

test('stays open while the pointer moves from the trigger onto the tooltip', () => {
  const el = trigger('basic-2026-10-20-232303.zip')
  el.dispatch('pointerenter')
  assert.ok(visible())

  el.dispatch('pointerleave', { relatedTarget: bubble() })
  bubble().dispatch('pointerenter')
  assert.ok(visible(), 'pointer on the tooltip')

  // back onto the trigger and off again: still held by the trigger, then gone
  bubble().dispatch('pointerleave', { relatedTarget: el })
  el.dispatch('pointerenter')
  assert.ok(visible())
  el.dispatch('pointerleave')
  assert.equal(visible(), false)
})

test('closes when the pointer leaves the tooltip elsewhere', () => {
  const el = trigger('Connection refused')
  el.dispatch('pointerenter')
  el.dispatch('pointerleave', { relatedTarget: bubble() })
  bubble().dispatch('pointerenter')
  bubble().dispatch('pointerleave')
  assert.equal(visible(), false)
})

test('text that arrives while hovering shows at once', () => {
  const el = trigger('')
  el.dispatch('pointerenter')
  assert.equal(visible(), false)

  setText(el, 'ftp.example.com:21', '')
  assert.ok(visible())
  assert.equal(bubble().textContent, 'ftp.example.com:21')

  // and goes when the text goes
  setText(el, '', 'ftp.example.com:21')
  assert.equal(visible(), false)
  el.dispatch('pointerleave')
})

test('an empty trigger keeps the focused tooltip of another element', () => {
  const focused = trigger('ftp.example.com:21')
  const empty = trigger('')

  focused.dispatch('focus')
  assert.ok(visible())

  empty.dispatch('pointerenter')
  assert.ok(visible())
  assert.equal(bubble().textContent, 'ftp.example.com:21')
  empty.dispatch('pointerleave')
  assert.ok(visible())

  focused.dispatch('blur')
  assert.equal(visible(), false)
})

test('tooltipPosition prefers the space above the trigger when asked', async () => {
  const { tooltipPosition } = await import('../../js/tooltip.js')
  const trigger = { top: 200, right: 300, bottom: 220, left: 100 }
  const size = { width: 150, height: 40 }
  const viewport = { width: 1000, height: 800 }

  // default: below the trigger
  const below = tooltipPosition(trigger, size, viewport).top
  assert.ok(below > trigger.bottom)

  // .top: above the trigger, it ends before the trigger starts
  const above = tooltipPosition(trigger, size, viewport, true).top
  assert.ok(above + size.height < trigger.top)

  // no room above: falls back to below
  const nearTop = { top: 10, right: 300, bottom: 30, left: 100 }
  assert.ok(tooltipPosition(nearTop, size, viewport, true).top > nearTop.bottom)
})
