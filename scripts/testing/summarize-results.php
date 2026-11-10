<?php

$output = file_get_contents($argv[1]);
if (str_starts_with($output, "\xff\xfe")) {
    $output = mb_convert_encoding(substr($output, 2), 'UTF-8', 'UTF-16LE');
}
$result = json_decode($output, true);
if (! is_array($result)) {
    echo implode("\n", array_slice(explode("\n", $output), -20));
    exit;
}
echo json_encode(array_intersect_key($result, array_flip(['result', 'tests', 'passed', 'assertions', 'duration_ms', 'failed', 'errors', 'skipped'])), JSON_UNESCAPED_UNICODE).PHP_EOL;
foreach (array_merge($result['failures'] ?? [], $result['error_details'] ?? []) as $failure) {
    echo $failure['test'].': '.strtok($failure['message'], "\n").PHP_EOL;
}
