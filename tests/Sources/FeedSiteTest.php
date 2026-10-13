<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Sources\BodyMode;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\IdRule;

/**
 * @author shimomo
 */
final class FeedSiteTest extends TestCase
{
    private const string LEKUMO_BODY = '//div[contains(concat(" ", normalize-space(@class), " "), " entry-body ")]';

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param string $key
     * @param string $name
     * @param string $feedUrl
     * @param \Shimomo\KeirinNewsCollector\Sources\BodyMode $mode
     * @param ?string $bodyXPath
     * @param list<string> $fixed
     * @return void
     */
    #[Test]
    #[DataProvider('presets')]
    public function presetsHaveTheSpecifiedSettings(FeedSite $site, string $key, string $name, string $feedUrl, BodyMode $mode, ?string $bodyXPath, array $fixed): void
    {
        self::assertSame($key, $site->key);
        self::assertSame($name, $site->name);
        self::assertSame($feedUrl, $site->feedUrl);
        self::assertSame($mode, $site->bodyMode);
        self::assertSame($bodyXPath, $site->bodyXPath);
        self::assertSame($fixed, $site->fixedCategories);
    }

    /**
     * @return iterable<string, array{\Shimomo\KeirinNewsCollector\Sources\FeedSite, string, string, string, \Shimomo\KeirinNewsCollector\Sources\BodyMode, ?string, list<string>}>
     */
    public static function presets(): iterable
    {
        yield 'hakodateEvent' => [FeedSite::hakodateEvent(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/event/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'hakodateInformation' => [FeedSite::hakodateInformation(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/information/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'hakodateAnnouncer' => [FeedSite::hakodateAnnouncer(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/announcer/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'utsunomiya' => [FeedSite::utsunomiya(), 'utsunomiya', '宇都宮競輪', 'https://blog.utsunomiya-keirin.jp/notification/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'tachikawaInfo' => [FeedSite::tachikawaInfo(), 'tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/info/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'tachikawaEvent' => [FeedSite::tachikawaEvent(), 'tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/event/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'beppu' => [FeedSite::beppu(), 'beppu', '別府競輪', 'https://beppu-keirin.lekumo.biz/info/atom.xml', BodyMode::Page, '//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main', []];
        yield 'omiya' => [FeedSite::omiya(), 'omiya', '大宮競輪', 'https://www.keirin-saitama.jp/omiya/topics/feed/', BodyMode::Feed, null, []];
        yield 'seibuen' => [FeedSite::seibuen(), 'seibuen', '西武園競輪', 'https://www.keirin-saitama.jp/seibuen/topics/feed/', BodyMode::Feed, null, []];
        yield 'shizuokaInformation' => [FeedSite::shizuokaInformation(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/information/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['お知らせ']];
        yield 'shizuokaEventinfo' => [FeedSite::shizuokaEventinfo(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/eventinfo/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['イベント']];
        yield 'takamatsu' => [FeedSite::takamatsu(), 'takamatsu', '高松競輪', 'https://tkmtkeirin.blog.fc2.com/?xml', BodyMode::Feed, null, []];
        yield 'takeo' => [FeedSite::takeo(), 'takeo', '武雄競輪', 'https://www.keirin.city.takeo.lg.jp/news/index.rss', BodyMode::Feed, null, []];
        yield 'wakayamaNews' => [FeedSite::wakayamaNews(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/rss.xml', BodyMode::Page, '//div[@id="mainArea"]', []];
        yield 'wakayamaInterview' => [FeedSite::wakayamaInterview(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/interview/atom.xml', BodyMode::Feed, null, ['優勝者インタビュー']];
        yield 'wakayamaBlog' => [FeedSite::wakayamaBlog(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/blog/atom.xml', BodyMode::Feed, null, ['ブログ']];
        yield 'keirinMarche' => [FeedSite::keirinMarche(), 'keirin-marche', 'けいりんマルシェ', 'https://keirin-marche.jp/feed/', BodyMode::Feed, null, []];
        yield 'girlsKeirin' => [FeedSite::girlsKeirin(), 'girls-keirin', 'ガールズケイリン', 'https://keirin-marche.jp/girlskeirin/news/feed/', BodyMode::Feed, null, []];
        yield 'jik' => [FeedSite::jik(), 'jik', '日本競輪選手養成所', 'https://keirin-jik.jp/feed/', BodyMode::Feed, null, []];
        yield 'netkeirin' => [FeedSite::netkeirin(), 'netkeirin', 'netkeirin', 'https://keirin.netkeiba.com/news/news_rss.html', BodyMode::None, null, []];
    }

    /**
     * WordPress のフィード（静岡を含む）は guid の p=ID、無ければリンク末尾の数字 ＋ 公開日。
     *
     * @return void
     */
    #[Test]
    public function presetsUseTheSpecifiedIdRules(): void
    {
        $wordPress = [
            FeedSite::omiya(), FeedSite::seibuen(), FeedSite::keirinMarche(), FeedSite::girlsKeirin(), FeedSite::jik(),
            FeedSite::shizuokaInformation(), FeedSite::shizuokaEventinfo(),
        ];

        foreach ($wordPress as $site) {
            self::assertEquals(IdRule::wordPressGuid(), $site->idRule, $site->key);
        }

        self::assertEquals(IdRule::linkPattern('~blog-entry-(\d+)~'), FeedSite::takamatsu()->idRule);
        self::assertEquals(IdRule::linkPattern('~/archives/(\d+)~'), FeedSite::takeo()->idRule);
        self::assertEquals(IdRule::linkPattern('~[?&]id=(\d+)~'), FeedSite::netkeirin()->idRule);

        foreach ([FeedSite::hakodateEvent(), FeedSite::utsunomiya(), FeedSite::tachikawaInfo(), FeedSite::beppu(), FeedSite::wakayamaNews(), FeedSite::wakayamaInterview()] as $site) {
            self::assertEquals(IdRule::linkPath(), $site->idRule, $site->key);
        }
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsCarryTheSiteSpecificFixes(): void
    {
        $beppu = FeedSite::beppu();
        $wakayama = FeedSite::wakayamaNews();
        $netkeirin = FeedSite::netkeirin();

        self::assertSame([
            './/h2[contains(concat(" ", normalize-space(@class), " "), " section-title ")]',
            './/p[contains(concat(" ", normalize-space(@class), " "), " text-update ")]',
            './/div[contains(concat(" ", normalize-space(@class), " "), " entry-footer ")]',
        ], $beppu->removeXPaths);
        self::assertSame(['.//div[contains(concat(" ", normalize-space(@class), " "), " title01 ")]'], $wakayama->removeXPaths);
        self::assertSame('//div[contains(concat(" ", normalize-space(@class), " "), " title01 ")]', $wakayama->titleXPath);
        self::assertSame('~/news/~', $wakayama->linkFilter);
        self::assertTrue($wakayama->dateOnly);
        self::assertSame(' | 競輪ニュース - netkeirin（ネットケイリン）', $netkeirin->titleSuffix);
        self::assertSame(['news_detail_amp.html', 'news_detail.html'], $netkeirin->linkRewrite);
        self::assertFalse(FeedSite::takeo()->dateOnly);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsUseDistinctFeedUrls(): void
    {
        $urls = [];

        foreach (self::presets() as [$site]) {
            $urls[] = $site->feedUrl;
        }

        self::assertCount(20, $urls);
        self::assertCount(20, array_unique($urls));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsPageModesWithoutBodyXPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('FeedSite::$bodyXPath is required when the body comes from the article page.');

        new FeedSite('t', 'テスト', 'https://example.test/feed.xml', IdRule::linkPath(), BodyMode::Page);
    }
}
