import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { dirname, join, posix, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

const repository = 'https://github.com/agentic-actions/laravel'

const docs = fileURLToPath(new URL('..', import.meta.url))

/** The npm client's version, which every release moves with the Composer one. */
const release: string = JSON.parse(readFileSync(join(docs, '../js/package.json'), 'utf8')).version

/**
 * The docs link to each other as GitHub URLs or relative paths, so they work on
 * GitHub and Packagist. On the site, a link to another page of the docs stays
 * on the site, and a link that leaves docs/ goes to the file on GitHub.
 *
 * The pages under docs/site/ exist only for the site and include a file from
 * the repository's root (the README or the CHANGELOG), so their relative links
 * resolve from the root, as they do on GitHub.
 */
const docsPageOnGitHub = new RegExp(
  `^${repository.replace(/[.]/g, '\\.')}/blob/main/docs/([\\w-]+)\\.md(#.*)?$`
)

function siteHref(href: string, source: string): string {
  const page = href.match(docsPageOnGitHub)

  // (a) A docs page on GitHub becomes the site's own page. Written as a .md
  // path, so VitePress cleans it and its dead-link check still covers it.
  if (page) {
    return `/${page[1]}.md${page[2] ?? ''}`
  }

  // (b) A relative path that leaves docs/ goes to the file on GitHub.
  if (/^([a-z][a-z\d+.-]*:|#|\/)/i.test(href)) {
    return href
  }

  const [path, hash = ''] = href.split(/(?=#)/)
  const base = source.startsWith('site/') ? '..' : posix.dirname(source)
  const resolved = posix.normalize(posix.join(base, path))

  if (resolved.startsWith('../docs/')) {
    return `/${resolved.slice('../docs/'.length)}${hash}`
  }

  if (!resolved.startsWith('../')) {
    return href
  }

  const fromRoot = resolved.slice('../'.length)

  return `${repository}/${fromRoot.endsWith('/') ? 'tree' : 'blob'}/main/${fromRoot}${hash}`
}

/**
 * VitePress leaves an include it cannot read, or a region it cannot find, as
 * an empty page or the whole file, and builds on: here it fails the build.
 */
function checkIncludes(file: string): void {
  for (const [comment, target] of readFileSync(file, 'utf8').matchAll(/<!--\s*@include:\s*(.*?)\s*-->/g)) {
    const [path, region] = target.split('#')
    const included = join(dirname(file), path)
    const content = existsSync(included) ? readFileSync(included, 'utf8') : null
    const hasRegion = (marker: string) => new RegExp(`${marker} ${region}(?![\\w-])`).test(content ?? '')

    if (content === null || (region && !(hasRegion('#region') && hasRegion('#endregion')))) {
      throw new Error(`${relative(docs, file)}: include not found: ${comment}`)
    }
  }
}

/**
 * VitePress checks that each linked page exists; this checks that each
 * #fragment a built page links to is an id on its target page.
 */
function checkFragments(outDir: string): void {
  const decode = (text: string) =>
    text.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  const pages = new Map<string, string>()

  for (const file of readdirSync(outDir, { recursive: true }) as string[]) {
    if (file.endsWith('.html')) {
      pages.set(file.split(sep).join('/').replace(/\.html$/, ''), readFileSync(join(outDir, file), 'utf8'))
    }
  }

  const ids = new Map([...pages].map(([page, html]) => [page, new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((m) => decode(m[1])))]))
  const broken: string[] = []

  for (const [page, html] of pages) {
    for (const [, href] of html.matchAll(/\shref="([^"]*#[^"]+)"/g)) {
      if (/^([a-z][a-z\d+.-]*:|\/\/)/i.test(href)) {
        continue
      }

      const [path, fragment] = decode(href).split('#')
      const target = path === '' ? page : posix.join(path.startsWith('/') ? '' : posix.dirname(page), path).replace(/^\//, '').replace(/\.html$/, '').replace(/(^|\/)$/, '$1index')

      if (!ids.get(target)?.has(decodeURIComponent(fragment))) {
        broken.push(`${page}.html: ${href}`)
      }
    }
  }

  if (broken.length) {
    throw new Error(`Found ${broken.length} link(s) to a missing #fragment:\n${broken.join('\n')}`)
  }
}

/**
 * GitHub's heading ids, so a fragment such as #what-an-agents-tool-list-shows
 * points at the same heading on GitHub and on the site.
 */
function githubSlug(text: string): string {
  return text
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '')
    .replace(/ /g, '-')
}

export default defineConfig({
  title: 'Agentic Actions for Laravel',
  description:
    'Write each operation of a Laravel app once, as an Action class, and call it from web forms and JSON, Artisan, the queue, TypeScript, laravel/ai agents and MCP clients through one checked pipeline.',
  lang: 'en-US',
  cleanUrls: true,
  sitemap: {
    hostname: 'https://agentic-actions.com',
  },
  head: [
    ['meta', { name: 'theme-color', media: '(prefers-color-scheme: light)', content: '#ffffff' }],
    ['meta', { name: 'theme-color', media: '(prefers-color-scheme: dark)', content: '#1b1b1f' }],
  ],

  rewrites: {
    'site/:page': ':page',
  },

  buildEnd: (siteConfig) => checkFragments(siteConfig.outDir),

  markdown: {
    // Every token measures at least 4.6:1 on the code background, in both themes.
    theme: {
      light: 'github-light-high-contrast',
      dark: 'github-dark-high-contrast',
    },
    anchor: {
      slugify: githubSlug,
    },
    config(md) {
      md.core.ruler.push('agentic_actions_links', (state) => {
        const file: string = state.env?.realPath ?? state.env?.path ?? ''
        const source = relative(docs, file).split(sep).join('/')

        if (file && existsSync(file)) {
          checkIncludes(file)
        }

        for (const token of state.tokens) {
          for (const child of token.children ?? []) {
            if (child.type !== 'link_open') {
              continue
            }

            const href = child.attrGet('href')

            if (href) {
              child.attrSet('href', siteHref(href, source))
            }
          }
        }
      })
    },
  },

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started', activeMatch: '^/(?!changelog)[^/]+' },
      { text: 'Changelog', link: '/changelog' },
      {
        text: release,
        items: [
          { text: 'Changelog', link: '/changelog' },
          { text: 'Packagist', link: 'https://packagist.org/packages/agentic-actions/laravel' },
          { text: 'npm', link: 'https://www.npmjs.com/package/@agentic-actions/client' },
          { text: 'Tags', link: `${repository}/tags` },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Introduction',
        items: [
          { text: 'Getting started', link: '/getting-started' },
          { text: 'Setup', link: '/setup' },
          { text: 'Concepts', link: '/concepts' },
        ],
      },
      {
        text: 'Features',
        items: [
          { text: 'The copilot', link: '/copilot' },
          { text: 'Asking the person', link: '/asking' },
          { text: 'Tables', link: '/data' },
          { text: 'MCP', link: '/mcp' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Security', link: '/security' },
          { text: 'Testing', link: '/testing' },
          { text: 'Recipes', link: '/recipes' },
          { text: 'Migrating from laravel-actions', link: '/migrating-from-laravel-actions' },
        ],
      },
    ],

    outline: [2, 3],

    search: {
      provider: 'local',
    },

    editLink: {
      // Two pages are built from files outside docs/: edit those files.
      pattern: ({ filePath }) => {
        const source =
          { 'site/getting-started.md': 'README.md', 'site/changelog.md': 'CHANGELOG.md' }[filePath] ?? `docs/${filePath}`

        return `https://github.com/agentic-actions/laravel/edit/main/${source}`
      },
      text: 'Edit this page on GitHub',
    },

    socialLinks: [{ icon: 'github', link: repository }],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright (c) 2026 Hussam Abd and contributors',
    },
  },
})
