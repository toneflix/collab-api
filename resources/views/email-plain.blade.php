Hi {{ $name ?? 'there' }},
@foreach ($lines as $line)
    {!! is_array($line) ? $line['link'] ?? '' : html_entity_decode(strip_tags($line ?? '')) !!}
@endforeach
