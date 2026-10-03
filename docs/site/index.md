---
layout: home
title: Agentic Actions for Laravel

hero:
  name: Agentic Actions
  text: Write each Laravel operation once.
  tagline: Your app's own operations, called from its web forms and JSON, Artisan, the queue, typed TypeScript, laravel/ai agents and MCP clients, each through the same checked pipeline.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: GitHub
      link: https://github.com/agentic-actions/laravel

features:
  - title: One class for every caller
    details: Mark an Action class <code>#[Expose]</code> and it answers a web route with Precognition and a typed TypeScript function, and becomes an agent tool (with laravel/ai) and an MCP tool where its effect allows. The same class also runs from Artisan and the queue.
    link: /getting-started#what-that-one-class-gets
    linkText: What one class gets
  - title: One pipeline
    details: Every call goes through exposure, token abilities, tenant membership, <code>authorize()</code>, validation and <code>handle()</code>. The output schema is an allowlist, so undeclared keys never leave the server.
    link: /concepts#the-pipeline
    linkText: The pipeline
  - title: A copilot on your page
    details: Stream a laravel/ai agent into the page. Each tool call shows as a live row, and the page reloads the props the write touched.
    link: /copilot
    linkText: The copilot
  - title: Confirmation before risky calls
    details: When an agent calls a Destructive or External action, it runs only after the person confirms that call on a card the server builds.
    link: /copilot#confirmations
    linkText: Confirmations
  - title: Tables from a Read action
    details: The person sees the query's rows as a table. The model reads a short copy and says what stands out, so the numbers the person reads are the query's, never retyped.
    link: /data
    linkText: Tables
  - title: MCP with tokens and OAuth
    details: Claude Code, Cursor and Claude Desktop connect with a person's token. Claude and ChatGPT connectors sign in with OAuth on Laravel Passport.
    link: /mcp
    linkText: MCP
---

## One action

A draft blog post, written once. The same class answers a JSON call, a Blade form and `php artisan actions:run`, and, once your app adds laravel/ai or a token guard, an agent or an MCP client.

<!--@include: ../../README.md#create-post-->

[Get started](/getting-started) shows the install and the web and CLI callers; [the copilot](/copilot) and [MCP](/mcp) show agents and MCP clients.
