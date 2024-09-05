<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Aws\S3\S3UriParser as BaseS3UriParser;
use Psr\Http\Message\UriInterface;

class S3UriParser extends BaseS3UriParser
{
    /**
     * @param string|UriInterface $uri
     * @phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function parse($uri): array
    {
        if (is_string($uri) && preg_match('/s3\.amazonaws\.com/ui', $uri)) {
            $uri = str_replace('s3.amazonaws.com', 's3.us-east-1.amazonaws.com', $uri);
        }

        return parent::parse($uri);
    }
}
