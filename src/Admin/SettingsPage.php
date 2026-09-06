<?php
/**
 * Registers the plugin settings page.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Support\Settings;

/**
 * Renders the Polls settings submenu and Settings API fields.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'zw_poll_settings';
    private const PAGE = 'zw-poll-settings';
    private const CAPABILITY = 'manage_options';

    /**
     * Registers admin hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_filter('option_page_capability_' . self::OPTION_GROUP, [$this, 'optionPageCapability']);
    }

    /**
     * Adds a settings submenu under Polls.
     */
    public function addMenuPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . PollPostType::POST_TYPE,
            __('Instellingen', 'zw-poll'),
            __('Instellingen', 'zw-poll'),
            self::CAPABILITY,
            self::PAGE,
            [$this, 'render']
        );
    }

    /**
     * Returns the capability required by options.php for this option group.
     */
    public function optionPageCapability(): string
    {
        return self::CAPABILITY;
    }

    /**
     * Registers the settings schema and fields.
     */
    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitizeForSave'],
            'default' => Settings::defaults(),
        ]);

        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        add_settings_section(
            'zw_poll_display',
            __('Weergave', 'zw-poll'),
            [$this, 'renderDisplaySection'],
            self::PAGE
        );
        add_settings_field(
            'zw_poll_total_min_votes',
            __('Minimumaantal stemmen voor zichtbaar totaal', 'zw-poll'),
            [$this, 'renderTotalMinVotesField'],
            self::PAGE,
            'zw_poll_display',
            ['label_for' => 'zw_poll_total_min_votes']
        );

        add_settings_section(
            'zw_poll_voting',
            __('Stemmen', 'zw-poll'),
            [$this, 'renderVotingSection'],
            self::PAGE
        );
        add_settings_field(
            'zw_poll_rate_limit_enabled',
            __('Rate limiting', 'zw-poll'),
            [$this, 'renderRateLimitEnabledField'],
            self::PAGE,
            'zw_poll_voting'
        );
        add_settings_field(
            'zw_poll_rate_limit_max',
            __('Maximumaantal stemmen per periode', 'zw-poll'),
            [$this, 'renderRateLimitMaxField'],
            self::PAGE,
            'zw_poll_voting',
            ['label_for' => 'zw_poll_rate_limit_max']
        );
        add_settings_field(
            'zw_poll_rate_limit_window',
            __('Lengte van de periode (seconden)', 'zw-poll'),
            [$this, 'renderRateLimitWindowField'],
            self::PAGE,
            'zw_poll_voting',
            ['label_for' => 'zw_poll_rate_limit_window']
        );
        add_settings_field(
            'zw_poll_proxy_header',
            __('Proxy-header', 'zw-poll'),
            [$this, 'renderProxyHeaderField'],
            self::PAGE,
            'zw_poll_voting',
            ['label_for' => 'zw_poll_proxy_header']
        );

        add_settings_section(
            'zw_poll_uninstall',
            __('De-installatie', 'zw-poll'),
            [$this, 'renderUninstallSection'],
            self::PAGE
        );
        add_settings_field(
            'zw_poll_delete_data_on_uninstall',
            __('Gegevens verwijderen', 'zw-poll'),
            [$this, 'renderDeleteDataOnUninstallField'],
            self::PAGE,
            'zw_poll_uninstall'
        );
    }

    /**
     * Renders the settings page wrapper.
     */
    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Je hebt geen rechten om deze instellingen te beheren.', 'zw-poll'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Instellingen', 'zw-poll') . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Renders the voting section description.
     */
    public function renderVotingSection(): void
    {
        echo '<p>' . esc_html__(
            'Bepaal hoeveel stemmen er per IP-adres binnen een periode zijn toegestaan en welke header het IP-adres van de stemmer levert. De limiet remt misbruik af, maar is geen waterdichte garantie.',
            'zw-poll'
        ) . '</p>';
    }

    /**
     * Renders the display section description.
     */
    public function renderDisplaySection(): void
    {
        echo '<p>' . esc_html__(
            'Bepaal vanaf hoeveel stemmen het totale aantal standaard zichtbaar wordt. Een waarde van 0 toont het totaal altijd bij polls die de site-instelling volgen.',
            'zw-poll'
        ) . '</p>';
    }

    /**
     * Renders the site-wide total vote threshold.
     */
    public function renderTotalMinVotesField(): void
    {
        $this->renderNumber(
            'total_min_votes',
            Settings::TOTAL_MIN_VOTES_MIN,
            Settings::TOTAL_MIN_VOTES_MAX,
            __('Per poll kan hiervan worden afgeweken.', 'zw-poll')
        );
    }

    /**
     * Renders the uninstall section description.
     */
    public function renderUninstallSection(): void
    {
        echo '<p>' . esc_html__(
            'De polls zelf blijven altijd bestaan. Deze optie bepaalt alleen of de stemtabel, de salt, de instellingen en de pluginrechten worden verwijderd.',
            'zw-poll'
        ) . '</p>';
    }

    /**
     * Renders the rate-limit enabled checkbox.
     */
    public function renderRateLimitEnabledField(): void
    {
        $this->renderCheckbox(
            'rate_limit_enabled',
            __('Rate limiting inschakelen', 'zw-poll'),
            __('Uitgeschakeld: er geldt geen limiet op stemmen per IP-adres.', 'zw-poll')
        );
    }

    /**
     * Renders the rate-limit maximum field.
     */
    public function renderRateLimitMaxField(): void
    {
        $this->renderNumber('rate_limit_max', Settings::RATE_LIMIT_MAX_MIN, Settings::RATE_LIMIT_MAX_MAX);
    }

    /**
     * Renders the rate-limit window field.
     */
    public function renderRateLimitWindowField(): void
    {
        $this->renderNumber(
            'rate_limit_window',
            Settings::RATE_LIMIT_WINDOW_MIN,
            Settings::RATE_LIMIT_WINDOW_MAX,
            __('Wijzigingen gelden pas voor nieuwe periodes.', 'zw-poll')
        );
    }

    /**
     * Renders the proxy header dropdown.
     */
    public function renderProxyHeaderField(): void
    {
        $settings = Settings::get();
        $filter_registered = has_filter('zw_poll_client_ip') !== false;
        if ($filter_registered) {
            printf(
                '<input type="hidden" name="%s" value="%s" />',
                esc_attr(self::fieldName('proxy_header')),
                esc_attr($settings['proxy_header'])
            );
        }

        printf(
            '<select id="zw_poll_proxy_header" name="%s"%s>',
            esc_attr(self::fieldName('proxy_header')),
            disabled($filter_registered, true, false)
        );
        foreach (self::proxyHeaderLabels() as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($settings['proxy_header'], $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        if ($filter_registered) {
            echo '<p class="description">' . esc_html__(
                'Deze instelling wordt in code bepaald door het zw_poll_client_ip-filter.',
                'zw-poll'
            ) . '</p>';
            return;
        }

        echo '<p class="description">' . esc_html__(
            'Standaard gebruikt de plugin REMOTE_ADDR. Kies alleen een header die je proxy gegarandeerd zelf zet. Gebruik X-Forwarded-For alleen als je proxy die header volledig overschrijft en geen door de bezoeker meegestuurde waarden doorlaat.',
            'zw-poll'
        ) . '</p>';
    }

    /**
     * Renders the uninstall cleanup checkbox.
     */
    public function renderDeleteDataOnUninstallField(): void
    {
        $this->renderCheckbox(
            'delete_data_on_uninstall',
            __('Verwijder bij de-installatie ook de stemtabel, de salt, de instellingen en de pluginrechten.', 'zw-poll'),
            __('Standaard blijven deze gegevens behouden.', 'zw-poll')
        );
    }

    /**
     * Renders a checkbox for a boolean settings key.
     *
     * @param string $key         Settings key.
     * @param string $label       Translated checkbox label.
     * @param string $description Optional translated description.
     */
    private function renderCheckbox(string $key, string $label, string $description = ''): void
    {
        printf(
            '<label><input type="checkbox" name="%s" value="1"%s /> %s</label>',
            esc_attr(self::fieldName($key)),
            checked(Settings::get()[$key], true, false),
            esc_html($label)
        );
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }

    /**
     * Renders a bounded number input for an integer settings key.
     *
     * @param string $key         Settings key.
     * @param int    $min         Minimum accepted value.
     * @param int    $max         Maximum accepted value.
     * @param string $description Optional translated description.
     */
    private function renderNumber(string $key, int $min, int $max, string $description = ''): void
    {
        printf(
            '<input type="number" id="zw_poll_%s" class="small-text" min="%d" max="%d" step="1" name="%s" value="%d" />',
            esc_attr($key),
            absint($min),
            absint($max),
            esc_attr(self::fieldName($key)),
            absint(Settings::get()[$key])
        );
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }

    /**
     * Returns the HTML field name for a settings key.
     *
     * @param string $key Settings key.
     */
    private static function fieldName(string $key): string
    {
        return Settings::OPTION . '[' . $key . ']';
    }

    /**
     * Returns labels for the proxy header choices.
     *
     * @return array<string, string>
     */
    private static function proxyHeaderLabels(): array
    {
        return [
            Settings::PROXY_HEADER_NONE => __('Geen (REMOTE_ADDR)', 'zw-poll'),
            Settings::PROXY_HEADER_CF_CONNECTING_IP => 'CF-Connecting-IP',
            Settings::PROXY_HEADER_X_FORWARDED_FOR => 'X-Forwarded-For',
            Settings::PROXY_HEADER_X_REAL_IP => 'X-Real-IP',
        ];
    }
}
