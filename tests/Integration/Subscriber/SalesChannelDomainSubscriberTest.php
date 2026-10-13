<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Subscriber;

use Doctrine\DBAL\Connection;
use Fastmon\Collector\Provisioning\SyncSitesMessage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;

/**
 * The unit test hands the subscriber an event it built itself. This writes a domain
 * the way the administration does, through the DAL, and checks that a sync message
 * reached the queue: the event name is a string the plugin spells out, and a release
 * that renamed it would otherwise first show up as a shop whose new domains never
 * arrive in fastmon.
 *
 * The queue is read from the `messenger_messages` table because that is where the
 * default transport puts it, inside the test transaction, so the message is gone again
 * with the test. On a shop configured for another transport the test says so rather
 * than failing on a table that is not in use.
 */
final class SalesChannelDomainSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Connection $database;

    protected function setUp(): void
    {
        $database = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;

        $dsn = $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? '';

        if (\is_string($dsn) && $dsn !== '' && !str_starts_with($dsn, 'doctrine://')) {
            self::markTestSkipped('the queue is not in the database on this shop');
        }
    }

    public function testADomainSavedInTheAdministrationQueuesASync(): void
    {
        $before = $this->queuedSyncs();

        $repository = static::getContainer()->get(SalesChannelDomainDefinition::ENTITY_NAME . '.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        // Language, currency and snippet set borrowed from the storefront's own domain:
        // the write needs valid ids, and which ones makes no difference to the event.
        $template = $this->database->fetchAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id,
                    LOWER(HEX(language_id)) AS language_id,
                    LOWER(HEX(currency_id)) AS currency_id,
                    LOWER(HEX(snippet_set_id)) AS snippet_set_id
             FROM sales_channel_domain
             LIMIT 1'
        );
        self::assertIsArray($template, 'the test database must have a sales channel domain');

        $repository->create([[
            'id' => Uuid::randomHex(),
            'salesChannelId' => $template['sales_channel_id'],
            'languageId' => $template['language_id'],
            'currencyId' => $template['currency_id'],
            'snippetSetId' => $template['snippet_set_id'],
            'url' => 'https://outlet.example',
        ]], Context::createDefaultContext());

        self::assertSame($before + 1, $this->queuedSyncs());
    }

    private function queuedSyncs(): int
    {
        // The transport's serializer puts the class in the JSON headers, where every
        // backslash is written twice, and each of those needs escaping again for LIKE.
        $type = '"type":"' . str_replace('\\', '\\\\\\\\', SyncSitesMessage::class) . '"';

        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE headers LIKE :type',
            ['type' => '%' . $type . '%']
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
