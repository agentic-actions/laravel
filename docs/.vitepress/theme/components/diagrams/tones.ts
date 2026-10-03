/** The accents a diagram may use, each for one meaning only (see style.css). */
export type Tone = 'neutral' | 'pass' | 'refuse' | 'wait' | 'agent' | 'mcp' | 'tenant'

/** The class that sets --tone, --tone-fill and --tone-line for a tone. */
export function toneClass(tone: Tone | undefined): string {
  return `aa-tone-${tone ?? 'neutral'}`
}
