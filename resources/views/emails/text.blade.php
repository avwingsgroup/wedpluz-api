{{ $subjectLine }}

{{ trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $body)))) }}

-- Wedpluz
