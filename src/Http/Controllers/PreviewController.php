<?php

namespace Goldnead\EmailTemplates\Http\Controllers;

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * CP preview for email templates.
 *
 * The template is rendered through the exact same path as a real send: the
 * EmailTemplateResolver produces the Bard→HTML body (BardHtmlRenderer), then
 * MergeVariables substitutes sample data into subject and body. So the preview
 * shows what a recipient would receive, only with placeholder recipient data.
 */
class PreviewController extends Controller
{
    public function __construct(
        protected EmailTemplateResolver $resolver,
        protected EmailTemplateCollectionManager $collection,
    ) {
    }

    /**
     * JSON preview endpoint. Accepts a template slug (`template`) or an
     * `email_templates` entry id (`id`), plus optional `merge_data` overrides.
     * Returns the merged subject + email-ready HTML body.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template' => 'nullable|string',
            'id' => 'nullable|string',
            'merge_data' => 'nullable|array',
        ]);

        $slug = $this->resolveSlug($data['template'] ?? null, $data['id'] ?? null);

        if ($slug === null) {
            return response()->json([
                'message' => __('email-templates::preview.not_found'),
            ], 404);
        }

        $template = $this->resolver->resolve($slug);

        if ($template === null) {
            return response()->json([
                'message' => __('email-templates::preview.not_found'),
            ], 404);
        }

        $mergeData = MergeVariables::sampleData($data['merge_data'] ?? []);

        return response()->json([
            'slug' => $template->slug,
            'title' => $template->title,
            'subject' => MergeVariables::apply($template->subject, $mergeData),
            'body' => MergeVariables::apply($template->body, $mergeData),
            'source' => $template->source,
            'merge_data' => $mergeData,
        ]);
    }

    /**
     * The CP preview page: a template picker, an editable sample-merge-data
     * panel, and a sandboxed iframe that renders the result of the JSON
     * endpoint. Reachable via the "Vorschau" entry action and the nav.
     */
    public function show(Request $request, ?string $id = null)
    {
        $templates = Entry::query()
            ->where('collection', EmailTemplateCollectionManager::HANDLE)
            ->get()
            ->map(fn (EntryContract $entry) => [
                'slug' => $entry->slug(),
                'title' => (string) ($entry->value('title') ?? $entry->slug()),
            ])
            ->sortBy('title')
            ->values();

        $selectedSlug = null;

        if ($id !== null) {
            $entry = Entry::find($id);
            $selectedSlug = $entry?->slug();
        }

        $selectedSlug ??= $request->query('template')
            ?? ($templates->first()['slug'] ?? null);

        return view('email-templates::cp.preview', [
            'title' => __('email-templates::preview.page_title'),
            'templates' => $templates,
            'selectedSlug' => $selectedSlug,
            'sampleDataJson' => json_encode(
                MergeVariables::sampleData(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'postUrl' => cp_route('email-templates.preview.render'),
            'csrf' => csrf_token(),
        ]);
    }

    /**
     * Resolve the input to a template slug. An entry id is only accepted when
     * it belongs to the email_templates collection; a raw slug is returned
     * as-is (the resolver reports a missing template as null → 404).
     */
    protected function resolveSlug(?string $template, ?string $id): ?string
    {
        if ($id !== null && $id !== '') {
            $entry = Entry::find($id);

            if ($entry && optional($entry->collection())->handle() === EmailTemplateCollectionManager::HANDLE) {
                return $entry->slug();
            }

            return null;
        }

        if ($template !== null && $template !== '') {
            return $template;
        }

        return null;
    }
}
