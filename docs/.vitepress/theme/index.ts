/**
 * The site's theme: VitePress's default theme without its fonts, the tokens in
 * style.css, and the diagram kit.
 *
 * Every .vue file under components/diagrams, components/concepts and
 * components/home is registered globally under its file name, so a page under
 * docs/site/ uses it without an import. To add a diagram, add a file; never
 * edit this one. Never use a component in docs/*.md: those pages must keep
 * rendering on GitHub.
 *
 * The kit (components/diagrams), each documented in its own docblock:
 *
 *   <Figure caption="…">            the frame and caption around every diagram
 *   <Flow> <Step title="…" />       a sequence, left to right, stacking when narrow
 *   <Hub :callers="[…]">            a centre card with its callers around it
 *   <Card> <Pill> <Icon>            a box, a status pill, a small stroke icon
 *   <Window url="…">                an app or browser frame for UI mocks
 *   <CodeCard file="…">             a file tab around a fenced code block
 *   <Matrix :columns :rows>         rows by columns with yes / no / confirm marks
 *   <Columns> <Bubble> <ListRow> <MockButton> <Skeleton>   pieces of UI mocks
 *
 * A concept page puts a fenced block inside a component only with blank lines
 * around it, and starts no component line with four spaces:
 *
 *   <Figure caption="One class, every caller.">
 *   <CodeCard file="app/Actions/CreatePost.php">
 *
 *   ```php
 *   final class CreatePost extends Action {}
 *   ```
 *
 *   </CodeCard>
 *   </Figure>
 *
 * Tones (the tone prop): neutral, pass, refuse, wait, agent, mcp, tenant.
 */
import type { Component } from 'vue'
import type { Theme } from 'vitepress'
import DefaultTheme from 'vitepress/theme-without-fonts'
import './style.css'

const components = import.meta.glob<Component>(
  ['./components/diagrams/*.vue', './components/concepts/*.vue', './components/home/*.vue'],
  { eager: true, import: 'default' }
)

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    for (const [path, component] of Object.entries(components)) {
      app.component(path.slice(path.lastIndexOf('/') + 1, -'.vue'.length), component)
    }
  },
} satisfies Theme
