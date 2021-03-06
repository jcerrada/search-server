<?php

/*
 * This file is part of the Apisearch Server
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

declare(strict_types=1);

namespace Apisearch\Plugin\Elastica\Domain;

use Apisearch\Config\Config;
use Apisearch\Config\Synonym;
use Apisearch\Exception\ResourceNotAvailableException;
use Apisearch\Repository\RepositoryReference;
use Apisearch\Server\Exception\ParsedResourceNotAvailableException;
use Elastica\Type\Mapping;

/**
 * Class ItemElasticaWrapper.
 */
class ItemElasticaWrapper extends ElasticaWrapper
{
    /**
     * @var string
     *
     * Item type
     */
    const ITEM_TYPE = 'item';

    /**
     * Get item type.
     *
     * @return string
     */
    public function getItemType(): string
    {
        return self::ITEM_TYPE;
    }

    /**
     * Get index prefix.
     *
     * @return string
     */
    public function getIndexPrefix(): string
    {
        return 'apisearch_item';
    }

    /**
     * Get index name.
     *
     * @param RepositoryReference $repositoryReference
     *
     * @return string
     */
    public function getIndexName(RepositoryReference $repositoryReference): string
    {
        return $this->buildIndexReference(
            $repositoryReference,
            $this->getIndexPrefix()
        );
    }

    /**
     * Get index not available exception.
     *
     * @param string $message
     *
     * @return ResourceNotAvailableException
     */
    public function getIndexNotAvailableException(string $message): ResourceNotAvailableException
    {
        return ParsedResourceNotAvailableException::parsedIndexNotAvailable($message);
    }

    /**
     * Get index configuration.
     *
     * @param Config $config
     *
     * @return array
     */
    public function getImmutableIndexConfiguration(Config $config): array
    {
        $language = $config->getLanguage();

        $defaultAnalyzerFilter = [
            5 => 'lowercase',
            20 => 'asciifolding',
            50 => 'ngram_filter',
        ];

        $searchAnalyzerFilter = [
            5 => 'lowercase',
            50 => 'asciifolding',
        ];

        $indexConfiguration = [
            'number_of_shards' => $config->getShards(),
            'number_of_replicas' => $config->getReplicas(),
            'max_result_window' => 50000,
            'analysis' => [
                'analyzer' => [
                    'default' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [],
                    ],
                    'search_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [],
                    ],
                ],
                'filter' => [
                    'ngram_filter' => [
                        'type' => 'edge_ngram',
                        'min_gram' => 1,
                        'max_gram' => 20,
                        'token_chars' => [
                            'letter',
                        ],
                    ],
                ],
                'normalizer' => [
                    'exact_matching_normalizer' => [
                        'type' => 'custom',
                        'filter' => [
                            'lowercase',
                            'asciifolding',
                        ],
                    ],
                ],
            ],
        ];

        $stopWordsLanguage = ElasticaLanguages::getStopwordsLanguageByIso($language);
        if (!is_null($stopWordsLanguage)) {
            $defaultAnalyzerFilter[30] = 'stop_words';
            $searchAnalyzerFilter[30] = 'stop_words';
            $indexConfiguration['analysis']['filter']['stop_words'] = [
                'type' => 'stop',
                'stopwords' => $stopWordsLanguage,
            ];
        }

        $stemmer = ElasticaLanguages::getStemmerLanguageByIso($language);
        if (!is_null($stemmer)) {
            $searchAnalyzerFilter[35] = 'stemmer';
            $indexConfiguration['analysis']['filter']['stemmer'] = [
                'type' => 'stemmer',
                'name' => $stemmer,
            ];
        }

        $synonyms = $config->getSynonyms();
        if (!empty($synonyms)) {
            $defaultAnalyzerFilter[40] = 'synonym';
            $indexConfiguration['analysis']['filter']['synonym'] = [
                'type' => 'synonym',
                'synonyms' => array_map(function (Synonym $synonym) {
                    return $synonym->expand();
                }, $synonyms),
            ];
        }

        ksort($defaultAnalyzerFilter, SORT_NUMERIC);
        ksort($searchAnalyzerFilter, SORT_NUMERIC);
        $indexConfiguration['analysis']['analyzer']['default']['filter'] = array_values($defaultAnalyzerFilter);
        $indexConfiguration['analysis']['analyzer']['search_analyzer']['filter'] = array_values($searchAnalyzerFilter);

        return $indexConfiguration;
    }

    /**
     * Get index configuration.
     *
     * @param Config $config
     *
     * @return array
     */
    public function getIndexConfiguration(Config $config): array
    {
        $language = $config->getLanguage();
        $defaultAnalyzerFilter = [
            5 => 'lowercase',
            20 => 'asciifolding',
            50 => 'ngram_filter',
        ];

        $searchAnalyzerFilter = [
            5 => 'lowercase',
            50 => 'asciifolding',
        ];

        $indexConfiguration = [
            'number_of_shards' => $config->getShards(),
            'number_of_replicas' => $config->getReplicas(),
            'max_result_window' => 50000,
            'analysis' => [
                'analyzer' => [
                    'default' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [],
                    ],
                    'search_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [],
                    ],
                ],
                'filter' => [
                    'ngram_filter' => [
                        'type' => 'edge_ngram',
                        'min_gram' => 1,
                        'max_gram' => 20,
                        'token_chars' => [
                            'letter',
                        ],
                    ],
                ],
                'normalizer' => [
                    'exact_matching_normalizer' => [
                        'type' => 'custom',
                        'filter' => [
                            'lowercase',
                            'asciifolding',
                        ],
                    ],
                ],
            ],
        ];

        $stopWordsLanguage = ElasticaLanguages::getStopwordsLanguageByIso($language);
        if (!is_null($stopWordsLanguage)) {
            $defaultAnalyzerFilter[30] = 'stop_words';
            $searchAnalyzerFilter[30] = 'stop_words';
            $indexConfiguration['analysis']['filter']['stop_words'] = [
                'type' => 'stop',
                'stopwords' => $stopWordsLanguage,
            ];
        }

        $stemmer = ElasticaLanguages::getStemmerLanguageByIso($language);
        if (!is_null($stemmer)) {
            $searchAnalyzerFilter[35] = 'stemmer';
            $indexConfiguration['analysis']['filter']['stemmer'] = [
                'type' => 'stemmer',
                'name' => $stemmer,
            ];
        }

        $synonyms = $config->getSynonyms();
        if (!empty($synonyms)) {
            $defaultAnalyzerFilter[40] = 'synonym';
            $indexConfiguration['analysis']['filter']['synonym'] = [
                'type' => 'synonym',
                'synonyms' => array_map(function (Synonym $synonym) {
                    return $synonym->expand();
                }, $synonyms),
            ];
        }

        ksort($defaultAnalyzerFilter, SORT_NUMERIC);
        ksort($searchAnalyzerFilter, SORT_NUMERIC);
        $indexConfiguration['analysis']['analyzer']['default']['filter'] = array_values($defaultAnalyzerFilter);
        $indexConfiguration['analysis']['analyzer']['search_analyzer']['filter'] = array_values($searchAnalyzerFilter);

        return $indexConfiguration;
    }

    /**
     * Build index mapping.
     *
     * @param Mapping $mapping
     * @param Config  $config
     */
    public function buildIndexMapping(
        Mapping $mapping,
        Config $config
    ) {
        $mapping->setParam('dynamic_templates', [
            [
                'dynamic_metadata_as_keywords' => [
                    'path_match' => 'indexed_metadata.*',
                    'match_mapping_type' => 'string',
                    'mapping' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
            [
                'dynamic_searchable_metadata_as_text' => [
                    'path_match' => 'searchable_metadata.*',
                    'mapping' => [
                        'type' => 'text',
                        'analyzer' => 'default',
                        'search_analyzer' => 'search_analyzer',
                    ],
                ],
            ],
            [
                'dynamic_arrays_as_nested' => [
                    'path_match' => 'indexed_metadata.*',
                    'match_mapping_type' => 'object',
                    'mapping' => [
                        'type' => 'nested',
                    ],
                ],
            ],
        ]);

        $sourceExcludes = [];
        if (!$config->shouldSearchableMetadataBeStored()) {
            $sourceExcludes = [
                'searchable_metadata',
                'exact_matching_metadata',
            ];
        }

        $mapping->setSource(['excludes' => $sourceExcludes]);

        $mapping->setProperties([
            'uuid' => [
                'type' => 'object',
                'dynamic' => 'strict',
                'properties' => [
                    'id' => [
                        'type' => 'keyword',
                    ],
                    'type' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
            'coordinate' => ['type' => 'geo_point'],
            'metadata' => [
                'type' => 'object',
                'dynamic' => true,
                'enabled' => false,
            ],
            'indexed_metadata' => [
                'type' => 'object',
                'dynamic' => true,
            ],
            'searchable_metadata' => [
                'type' => 'object',
                'dynamic' => true,
            ],
            'exact_matching_metadata' => [
                'type' => 'keyword',
                'normalizer' => 'exact_matching_normalizer',
            ],
            'suggest' => [
                'type' => 'completion',
                'analyzer' => 'search_analyzer',
                'search_analyzer' => 'search_analyzer',
            ],
        ]);
    }
}
