{{-- Rendered flow document. $html comes from FlowRenderer (raw HTML stripped + sanitized). --}}
<style>
    .flow-doc { line-height: 1.7; color: #1e293b; }
    .flow-doc h1 { font-size: 1.5rem; font-weight: 600; margin: 1.2rem 0 .6rem; }
    .flow-doc h2 { font-size: 1.25rem; font-weight: 600; margin: 1.2rem 0 .5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: .25rem; }
    .flow-doc h3 { font-size: 1.1rem; font-weight: 600; margin: 1rem 0 .4rem; }
    .flow-doc h4, .flow-doc h5, .flow-doc h6 { font-weight: 600; margin: .8rem 0 .3rem; }
    .flow-doc p { margin: .5rem 0; }
    .flow-doc ul { list-style: disc; padding-left: 1.5rem; margin: .5rem 0; }
    .flow-doc ol { list-style: decimal; padding-left: 1.5rem; margin: .5rem 0; }
    .flow-doc a { color: #4f46e5; text-decoration: underline; }
    .flow-doc blockquote { border-left: 4px solid #cbd5e1; padding-left: .75rem; color: #475569; margin: .5rem 0; }
    .flow-doc code { background: #f1f5f9; padding: 0 .25rem; border-radius: .25rem; font-size: .875em; }
    .flow-doc pre:not(.mermaid) { background: #0f172a; color: #e2e8f0; padding: .75rem; border-radius: .375rem; overflow-x: auto; }
    .flow-doc pre:not(.mermaid) code { background: none; padding: 0; }
    .flow-doc hr { margin: 1rem 0; border-color: #e2e8f0; }
    .flow-doc table { border-collapse: collapse; margin: .5rem 0; font-size: .875rem; }
    .flow-doc th, .flow-doc td { border: 1px solid #cbd5e1; padding: .3rem .6rem; }
    .flow-doc th { background: #f8fafc; }
    .flow-doc .cits-flow { margin: 1rem 0; padding: 1rem; border: 1px solid #e2e8f0; border-radius: .5rem; background: #fff; overflow-x: auto; text-align: center; }
    .flow-doc .cits-flow.cits-zoom { overflow: hidden; padding: 0; text-align: left; }
    .flow-doc .cits-flow pre.mermaid { text-align: left; color: #94a3b8; font-size: .75rem; white-space: pre-wrap; }
    .flow-doc .cits-flow.is-error { border-color: #fca5a5; background: #fef2f2; }
    .flow-doc .cits-flow-error { color: #dc2626; font-size: .875rem; text-align: left; margin-top: .5rem; white-space: pre-wrap; }
    .flow-doc .cits-flow svg { max-width: 100%; height: auto; margin: 0 auto; }
</style>
<article class="flow-doc rounded-lg bg-white p-6 shadow-sm" data-flow-doc>{!! $html !!}</article>
