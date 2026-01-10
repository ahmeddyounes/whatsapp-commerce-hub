<?php
/**
 * Template Manager
 *
 * Manages WhatsApp message templates including syncing, caching, and rendering.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Templates;

use WhatsAppCommerceHub\Infrastructure\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Core\SettingsManager;
use WhatsAppCommerceHub\Support\Logger;
use WhatsAppCommerceHub\Domain\Exceptions\WchException;
use WhatsAppCommerceHub\Domain\Exceptions\ApiException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Manager Class
 *
 * Handles WhatsApp message template operations including:
 * - Syncing templates from WhatsApp Business API
 * - Caching and retrieving templates
 * - Rendering templates with variable substitution
 * - Tracking template usage statistics
 */
class TemplateManager
{
    /**
     * Option name for storing templates
     */
    private const TEMPLATES_OPTION = 'wch_message_templates';

    /**
     * Option name for last sync timestamp
     */
    private const LAST_SYNC_OPTION = 'wch_templates_last_sync';

    /**
     * Transient prefix for template usage stats
     */
    private const USAGE_STATS_TRANSIENT_PREFIX = 'wch_template_usage_';

    /**
     * Supported template categories
     */
    private const SUPPORTED_CATEGORIES = [
        'order_confirmation',
        'order_status_update',
        'shipping_update',
        'abandoned_cart',
        'promotional',
    ];

    /**
     * Constructor
     *
     * @param WhatsAppApiClient $apiClient WhatsApp API client for template operations
     * @param SettingsManager $settings Settings manager for configuration
     * @param Logger $logger Logger for tracking operations
     */
    public function __construct(
        private readonly WhatsAppApiClient $apiClient,
        private readonly SettingsManager $settings,
        private readonly Logger $logger
    ) {
    }

    /**
     * Sync templates from WhatsApp API
     *
     * Fetches all message templates from WhatsApp Business API and stores them.
     *
     * @throws WchException If WABA ID is not configured or sync fails
     * @return array<string, mixed> Array of synced templates
     */
    public function syncTemplates(): array
    {
        $wabaId = $this->settings->get('api.waba_id');
        if (empty($wabaId)) {
            throw new WchException(
                'WhatsApp Business Account ID not configured',
                'TEMPLATE_SYNC_ERROR'
            );
        }

        $this->logger->info('Starting template sync from WhatsApp API');

        try {
            // Fetch templates from API
            $templates = $this->fetchTemplatesFromApi($wabaId);

            // Filter templates by supported categories
            $filteredTemplates = $this->filterTemplatesByCategory($templates);

            // Store templates in option
            update_option(self::TEMPLATES_OPTION, $filteredTemplates, false);

            // Update last sync timestamp
            update_option(self::LAST_SYNC_OPTION, time(), false);

            $this->logger->info('Template sync completed successfully', [
                'total_templates' => count($templates),
                'filtered_templates' => count($filteredTemplates),
            ]);

            return $filteredTemplates;
        } catch (ApiException $e) {
            $this->logger->error('Template sync failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            
            throw new WchException(
                'Failed to sync templates: ' . $e->getMessage(),
                'TEMPLATE_SYNC_ERROR',
                ['original_error' => $e->getMessage()]
            );
        }
    }

    /**
     * Fetch templates from WhatsApp API
     *
     * @param string $wabaId WhatsApp Business Account ID
     * @throws ApiException If API call fails
     * @return array<int, mixed> Raw templates from API
     */
    private function fetchTemplatesFromApi(string $wabaId): array
    {
        $allTemplates = [];
        $afterCursor = null;

        do {
            $params = [
                'fields' => 'name,status,category,language,components',
                'limit' => 100,
            ];

            if ($afterCursor) {
                $params['after'] = $afterCursor;
            }

            $endpoint = "/{$wabaId}/message_templates";
            $response = $this->makeApiRequest($endpoint, $params);

            if (!isset($response['data']) || !is_array($response['data'])) {
                throw new ApiException(
                    'Invalid API response format',
                    'INVALID_RESPONSE'
                );
            }

            $allTemplates = array_merge($allTemplates, $response['data']);

            // Check if there are more pages
            $afterCursor = $response['paging']['cursors']['after'] ?? null;
        } while ($afterCursor);

        return $allTemplates;
    }

    /**
     * Make an API request
     *
     * @param string $endpoint API endpoint
     * @param array<string, mixed> $params Query parameters
     * @throws ApiException If API call fails
     * @return array<string, mixed> API response
     */
    private function makeApiRequest(string $endpoint, array $params = []): array
    {
        $accessToken = $this->settings->get('api.access_token');
        $apiVersion = $this->settings->get('api.version', 'v18.0');

        if (empty($accessToken)) {
            throw new ApiException(
                'WhatsApp API access token not configured',
                'MISSING_TOKEN'
            );
        }

        $url = "https://graph.facebook.com/{$apiVersion}{$endpoint}";
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new ApiException(
                'API request failed: ' . $response->get_error_message(),
                'REQUEST_FAILED'
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode !== 200) {
            $errorMessage = $data['error']['message'] ?? 'Unknown error';
            throw new ApiException(
                "API returned error: {$errorMessage}",
                'API_ERROR',
                ['status_code' => $statusCode, 'response' => $data]
            );
        }

        return $data;
    }

    /**
     * Filter templates by supported categories
     *
     * @param array<int, mixed> $templates Raw templates from API
     * @return array<string, mixed> Filtered templates indexed by name
     */
    private function filterTemplatesByCategory(array $templates): array
    {
        $filtered = [];

        foreach ($templates as $template) {
            $category = $this->mapTemplateCategory($template['category'] ?? '');
            
            if (in_array($category, self::SUPPORTED_CATEGORIES, true) && 
                ($template['status'] ?? '') === 'APPROVED') {
                $filtered[$template['name']] = $template;
            }
        }

        return $filtered;
    }

    /**
     * Map WhatsApp API category to internal category
     *
     * @param string $category WhatsApp API category
     * @return string Internal category name
     */
    private function mapTemplateCategory(string $category): string
    {
        return match (strtolower($category)) {
            'marketing' => 'promotional',
            'utility' => 'order_status_update',
            default => strtolower($category),
        };
    }

    /**
     * Get all cached templates
     *
     * @return array<string, mixed> Array of templates
     */
    public function getTemplates(): array
    {
        return get_option(self::TEMPLATES_OPTION, []);
    }

    /**
     * Get a specific template by name
     *
     * @param string $name Template name
     * @return array<string, mixed>|null Template data or null if not found
     */
    public function getTemplate(string $name): ?array
    {
        $templates = $this->getTemplates();
        return $templates[$name] ?? null;
    }

    /**
     * Render a template with variables
     *
     * @param string $name Template name
     * @param array<string, string> $variables Variables to replace in template
     * @throws WchException If template not found or rendering fails
     * @return string Rendered template text
     */
    public function renderTemplate(string $name, array $variables = []): string
    {
        $template = $this->getTemplate($name);
        
        if (!$template) {
            throw new WchException(
                "Template not found: {$name}",
                'TEMPLATE_NOT_FOUND'
            );
        }

        // Track usage
        $this->trackTemplateUsage($name);

        // Get body component
        $bodyComponent = null;
        foreach ($template['components'] ?? [] as $component) {
            if (($component['type'] ?? '') === 'BODY') {
                $bodyComponent = $component;
                break;
            }
        }

        if (!$bodyComponent || empty($bodyComponent['text'])) {
            throw new WchException(
                "Template has no body component: {$name}",
                'INVALID_TEMPLATE'
            );
        }

        // Replace variables in text
        return $this->replaceVariables($bodyComponent['text'], $variables);
    }

    /**
     * Replace variables in template text
     *
     * Variables in format {{1}}, {{2}}, etc. are replaced with provided values.
     *
     * @param string $text Template text with variables
     * @param array<string, string> $variables Variables to replace (1-indexed)
     * @return string Text with variables replaced
     */
    private function replaceVariables(string $text, array $variables): string
    {
        foreach ($variables as $index => $value) {
            $text = str_replace("{{{$index}}}", $value, $text);
        }
        return $text;
    }

    /**
     * Track template usage
     *
     * @param string $templateName Template name
     */
    private function trackTemplateUsage(string $templateName): void
    {
        $transientKey = self::USAGE_STATS_TRANSIENT_PREFIX . md5($templateName);
        $stats = get_transient($transientKey);

        if ($stats === false) {
            $stats = [
                'template_name' => $templateName,
                'usage_count' => 0,
                'last_used' => null,
            ];
        }

        $stats['usage_count']++;
        $stats['last_used'] = time();

        // Store for 30 days
        set_transient($transientKey, $stats, 30 * DAY_IN_SECONDS);
    }

    /**
     * Get usage statistics for a template
     *
     * @param string $templateName Template name
     * @return array<string, mixed>|null Usage statistics or null if no stats
     */
    public function getTemplateUsageStats(string $templateName): ?array
    {
        $transientKey = self::USAGE_STATS_TRANSIENT_PREFIX . md5($templateName);
        $stats = get_transient($transientKey);
        return $stats !== false ? $stats : null;
    }

    /**
     * Get usage statistics for all templates
     *
     * @return array<string, mixed> Array of usage statistics indexed by template name
     */
    public function getAllUsageStats(): array
    {
        $templates = $this->getTemplates();
        $allStats = [];

        foreach (array_keys($templates) as $templateName) {
            $stats = $this->getTemplateUsageStats($templateName);
            if ($stats) {
                $allStats[$templateName] = $stats;
            }
        }

        return $allStats;
    }

    /**
     * Get last sync timestamp
     *
     * @return int|null Unix timestamp or null if never synced
     */
    public function getLastSyncTime(): ?int
    {
        $timestamp = get_option(self::LAST_SYNC_OPTION);
        return $timestamp !== false ? (int) $timestamp : null;
    }

    /**
     * Clear template cache
     */
    public function clearCache(): void
    {
        delete_option(self::TEMPLATES_OPTION);
        delete_option(self::LAST_SYNC_OPTION);
        $this->logger->info('Template cache cleared');
    }
}
