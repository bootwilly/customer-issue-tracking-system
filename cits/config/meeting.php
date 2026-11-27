<?php

return [
    'markdown_max_bytes' => (int) env('MEETING_MARKDOWN_MAX_BYTES', 200_000),
    // pictures per discussion item (pasted screenshots / annotated drawings)
    'item_images_max' => (int) env('MEETING_ITEM_IMAGES_MAX', 10),
];
