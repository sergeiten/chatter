<?php

namespace DevDojo\Chatter\Helpers;

class ChatterHelper
{
    /**
     * Convert any string to a color code.
     *
     * @param string $string
     *
     * @return string
     */
    public static function stringToColorCode($string)
    {
        $code = dechex(crc32($string));

        return substr($code, 0, 6);
    }

    /**
     * User link.
     *
     * @param mixed $user
     *
     * @return string
     */
    public static function userLink($user)
    {
        $url = config('chatter.user.relative_url_to_profile', '');

        if ('' === $url) {
            return '#_';
        }

        return static::replaceUrlParameter($url, $user);
    }

    /**
     * Replace url parameter.
     *
     * @param string $url
     * @param mixed $source
     *
     * @return string
     */
    private static function replaceUrlParameter($url, $source)
    {
        $parameter = static::urlParameter($url);

        return str_replace('{' . $parameter . '}', $source[$parameter], $url);
    }

    /**
     * Url parameter.
     *
     * @param string $url
     *
     * @return string
     */
    private static function urlParameter($url)
    {
        $start = strpos($url, '{') + 1;

        $length = strpos($url, '}') - $start;

        return substr($url, $start, $length);
    }

    /**
     * This function will demote H1 to H2, H2 to H3, H4 to H5, etc.
     * this will help with SEO so there are not multiple H1 tags
     * on the same page.
     *
     * @param HTML string
     *
     * @return HTML string
     */
    public static function demoteHtmlHeaderTags($html)
    {
        $originalHeaderTags = [];
        $demotedHeaderTags = [];

        foreach (range(100, 1) as $index) {
            $originalHeaderTags[] = '<h' . $index . '>';

            $originalHeaderTags[] = '</h' . $index . '>';

            $demotedHeaderTags[] = '<h' . ($index + 1) . '>';

            $demotedHeaderTags[] = '</h' . ($index + 1) . '>';
        }

        return str_ireplace($originalHeaderTags, $demotedHeaderTags, $html);
    }

    public static function hangulChar2latin($string)
    {
        $prncTable = Array(
            Array("g", "k", "n", "d", "t", "r", "m", "b", "p", "s", "s", "", "j", "ch", "ch", "k", "t", "p", "h"),
            Array("a", "a", "ya", "ye", "o", "e", "yo", "ye", "o", "ua", "ue", "ui", "yo", "u", "ue", "ue", "ui", "yu", "u", "ui", "i"),
            Array("", "k", "k", "k", "n", "nj", "n", "d", "l", "l", "m", "b", "l", "l", "l", "l", "m", "b", "b", "s", "s", "ng", "t", "t", "k", "t", "p", "ck")
        );

        //-- unicode로 변환
        $utf8 = iconv(ini_get("default_charset"), "UTF-8", $string);
        $unicode = ((ord($utf8[0]) & 0x0F) << 12) | ((ord($utf8[1]) & 0x3F) << 6) | (ord($utf8[2]) & 0x3F);
        $unicode -= 0xAC00;    //-- 한글 코드 시작 offset ('가')
        //-- 초중종성 분리
        $cho = (int)($unicode / (21 * 28));
        $unicode %= (21 * 28);
        $jung = (int)($unicode / 28);
        $jong = $unicode % 28;
        //-- 영문변환리턴 (아래 코드를 이용하면 '한'이 'han'과 같은식으로 리턴됨)
        return ($prncTable[0][$cho] . $prncTable[1][$jung] . $prncTable[2][$jong]);
    }

    public static function hangulSlug($string)
    {
        $eng = "";
        $length = mb_strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($string, $i, 1);
            $code = ord($char);
            if ($code < 127) {
                $eng .= $char;
                continue;
            }

            $eng .= self::hangulChar2latin($char);
        }

        return $eng;
    }
}
