<?php

namespace App\Support;

use App\Models\AiDocument;
use Illuminate\Support\Str;

/**
 * Deriva "temas" (tópicos tipo KB de ITSM) a partir del cuerpo markdown de un
 * documento: cada encabezado de nivel 2–4 con contenido propio se vuelve un tema
 * navegable (título + cuerpo). Se ignora el H1 (título del documento) y los
 * encabezados de categoría sin contenido directo (p. ej. "Síntomas y solución").
 */
class MarkdownTopics
{
    /**
     * @return array<int,array{anchor:string,title:string,body:string,excerpt:string}>
     */
    public static function parse(?string $md): array
    {
        $lines = preg_split('/\R/', (string) $md) ?: [];
        $topics = [];
        $cur = null;

        $flush = function () use (&$cur, &$topics) {
            if ($cur !== null) {
                $body = trim($cur['body']);
                if (mb_strlen($body) >= 20) { // descarta encabezados de categoría sin contenido
                    $topics[] = [
                        'anchor'  => $cur['anchor'],
                        'title'   => $cur['title'],
                        'body'    => $body,
                        'excerpt' => self::excerpt($body),
                    ];
                }
            }
            $cur = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{2,4})\s+(.+?)\s*$/', $line, $m)) {
                $flush();
                $title = trim($m[2]);
                $cur = ['title' => $title, 'anchor' => Str::slug($title) ?: ('tema-' . count($topics)), 'body' => ''];
            } elseif (preg_match('/^#\s+/', $line)) {
                $flush(); // H1 = título del documento
            } elseif ($cur !== null) {
                $cur['body'] .= $line . "\n";
            }
        }
        $flush();

        return $topics;
    }

    /** Temas de un documento, enriquecidos con sus datos (sistema, cliente…). */
    public static function fromDocument(AiDocument $doc): array
    {
        return array_map(fn ($t) => array_merge($t, [
            'doc_id'    => $doc->id,
            'doc_title' => $doc->title,
            'system'    => $doc->relationLoaded('system') && $doc->system ? ['id' => $doc->system->id, 'label' => $doc->system->label] : null,
            'audience'  => $doc->audience,
        ]), self::parse($doc->body_md));
    }

    private static function excerpt(string $body): string
    {
        $t = preg_replace('/^#{1,6}\s+/m', '', $body);
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[*_`>#-]/', ' ', $t)));
        return Str::limit($t, 160);
    }
}
