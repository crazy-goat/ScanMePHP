<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1;

/**
 * The GS1 application identifier table.
 *
 * An application identifier is two to four digits that say what the data after
 * it means: (01) a trade item number, (17) an expiry date, (10) a batch. Three
 * facts about each are needed to build a symbol, and they are independent of
 * one another:
 *
 * - whether the identifier exists at all;
 * - how long its data may be;
 * - whether an FNC1 must follow that data when another element string comes
 *   after it.
 *
 * The third is the one that surprises. It is *not* "is the length fixed": AI
 * (402) carries exactly seventeen digits and still needs a separator, because
 * predefined length in GS1 means the identifier appears on a published list,
 * not that its length happens to be constant. Get it wrong and the scanner
 * reads the next AI as more of this one\'s data — a symbol that scans, as
 * something else.
 *
 * So the table is derived rather than transcribed. tools/gs1_reference.py
 * offers every two-, three- and four-digit string to zxing-cpp and keeps what
 * it accepts, then finds each one\'s legal lengths and separator rule by
 * probing. tests/fixtures/gs1_ai_reference.csv is the frozen result and
 * Gs1ReferenceTest compares this table against it row for row.
 *
 * What is deliberately absent: character sets and check digits. The encoder
 * behind the fixture validates neither — it accepts \'(3103)00018A\' and the
 * thirteenth month — so neither is shipped here. See Gs1Test, which asserts
 * that boundary rather than leaving it silent.
 */
final class ApplicationIdentifier
{
    /**
     * Identifier to [shortest data, longest data, no separator needed].
     *
     * PHP narrows a numeric string key to an integer, so '10' is stored as
     * 10 while '01' stays a string. Lookups are unaffected — the same
     * narrowing applies to the subscript — but enumeration is, which is what
     * all() is for: nothing outside this class should see the key type.
     *
     * @var array<int|string, array{int, int, bool}>
     */
    public const IDENTIFIERS = [
        '00' => [18, 18, true], '01' => [14, 14, true], '02' => [14, 14, true], '03' => [14, 14, true],
        '10' => [1, 20, false], '11' => [6, 6, true], '12' => [6, 6, true], '13' => [6, 6, true],
        '15' => [6, 6, true], '16' => [6, 6, true], '17' => [6, 6, true], '20' => [2, 2, true],
        '21' => [1, 20, false], '22' => [1, 20, false], '30' => [1, 8, false], '37' => [1, 8, false],
        '90' => [1, 30, false], '91' => [1, 90, false], '92' => [1, 90, false], '93' => [1, 90, false],
        '94' => [1, 90, false], '95' => [1, 90, false], '96' => [1, 90, false], '97' => [1, 90, false],
        '98' => [1, 90, false], '99' => [1, 90, false], '235' => [1, 28, true], '240' => [1, 30, false],
        '241' => [1, 30, false], '242' => [1, 6, false], '243' => [1, 20, false], '250' => [1, 30, false],
        '251' => [1, 30, false], '253' => [13, 30, false], '254' => [1, 20, false], '255' => [13, 25, false],
        '400' => [1, 30, false], '401' => [1, 30, false], '402' => [17, 17, false], '403' => [1, 30, false],
        '410' => [13, 13, true], '411' => [13, 13, true], '412' => [13, 13, true], '413' => [13, 13, true],
        '414' => [13, 13, true], '415' => [13, 13, true], '416' => [13, 13, true], '417' => [13, 13, true],
        '420' => [1, 20, false], '421' => [4, 12, false], '422' => [3, 3, false], '423' => [3, 15, false],
        '424' => [3, 3, false], '425' => [3, 15, false], '426' => [3, 3, false], '427' => [1, 3, false],
        '710' => [1, 20, false], '711' => [1, 20, false], '712' => [1, 20, false], '713' => [1, 20, false],
        '714' => [1, 20, false], '715' => [1, 20, false], '716' => [1, 20, false], '717' => [1, 20, false],
        '3100' => [6, 6, true], '3101' => [6, 6, true], '3102' => [6, 6, true], '3103' => [6, 6, true],
        '3104' => [6, 6, true], '3105' => [6, 6, true], '3110' => [6, 6, true], '3111' => [6, 6, true],
        '3112' => [6, 6, true], '3113' => [6, 6, true], '3114' => [6, 6, true], '3115' => [6, 6, true],
        '3120' => [6, 6, true], '3121' => [6, 6, true], '3122' => [6, 6, true], '3123' => [6, 6, true],
        '3124' => [6, 6, true], '3125' => [6, 6, true], '3130' => [6, 6, true], '3131' => [6, 6, true],
        '3132' => [6, 6, true], '3133' => [6, 6, true], '3134' => [6, 6, true], '3135' => [6, 6, true],
        '3140' => [6, 6, true], '3141' => [6, 6, true], '3142' => [6, 6, true], '3143' => [6, 6, true],
        '3144' => [6, 6, true], '3145' => [6, 6, true], '3150' => [6, 6, true], '3151' => [6, 6, true],
        '3152' => [6, 6, true], '3153' => [6, 6, true], '3154' => [6, 6, true], '3155' => [6, 6, true],
        '3160' => [6, 6, true], '3161' => [6, 6, true], '3162' => [6, 6, true], '3163' => [6, 6, true],
        '3164' => [6, 6, true], '3165' => [6, 6, true], '3200' => [6, 6, true], '3201' => [6, 6, true],
        '3202' => [6, 6, true], '3203' => [6, 6, true], '3204' => [6, 6, true], '3205' => [6, 6, true],
        '3210' => [6, 6, true], '3211' => [6, 6, true], '3212' => [6, 6, true], '3213' => [6, 6, true],
        '3214' => [6, 6, true], '3215' => [6, 6, true], '3220' => [6, 6, true], '3221' => [6, 6, true],
        '3222' => [6, 6, true], '3223' => [6, 6, true], '3224' => [6, 6, true], '3225' => [6, 6, true],
        '3230' => [6, 6, true], '3231' => [6, 6, true], '3232' => [6, 6, true], '3233' => [6, 6, true],
        '3234' => [6, 6, true], '3235' => [6, 6, true], '3240' => [6, 6, true], '3241' => [6, 6, true],
        '3242' => [6, 6, true], '3243' => [6, 6, true], '3244' => [6, 6, true], '3245' => [6, 6, true],
        '3250' => [6, 6, true], '3251' => [6, 6, true], '3252' => [6, 6, true], '3253' => [6, 6, true],
        '3254' => [6, 6, true], '3255' => [6, 6, true], '3260' => [6, 6, true], '3261' => [6, 6, true],
        '3262' => [6, 6, true], '3263' => [6, 6, true], '3264' => [6, 6, true], '3265' => [6, 6, true],
        '3270' => [6, 6, true], '3271' => [6, 6, true], '3272' => [6, 6, true], '3273' => [6, 6, true],
        '3274' => [6, 6, true], '3275' => [6, 6, true], '3280' => [6, 6, true], '3281' => [6, 6, true],
        '3282' => [6, 6, true], '3283' => [6, 6, true], '3284' => [6, 6, true], '3285' => [6, 6, true],
        '3290' => [6, 6, true], '3291' => [6, 6, true], '3292' => [6, 6, true], '3293' => [6, 6, true],
        '3294' => [6, 6, true], '3295' => [6, 6, true], '3300' => [6, 6, true], '3301' => [6, 6, true],
        '3302' => [6, 6, true], '3303' => [6, 6, true], '3304' => [6, 6, true], '3305' => [6, 6, true],
        '3310' => [6, 6, true], '3311' => [6, 6, true], '3312' => [6, 6, true], '3313' => [6, 6, true],
        '3314' => [6, 6, true], '3315' => [6, 6, true], '3320' => [6, 6, true], '3321' => [6, 6, true],
        '3322' => [6, 6, true], '3323' => [6, 6, true], '3324' => [6, 6, true], '3325' => [6, 6, true],
        '3330' => [6, 6, true], '3331' => [6, 6, true], '3332' => [6, 6, true], '3333' => [6, 6, true],
        '3334' => [6, 6, true], '3335' => [6, 6, true], '3340' => [6, 6, true], '3341' => [6, 6, true],
        '3342' => [6, 6, true], '3343' => [6, 6, true], '3344' => [6, 6, true], '3345' => [6, 6, true],
        '3350' => [6, 6, true], '3351' => [6, 6, true], '3352' => [6, 6, true], '3353' => [6, 6, true],
        '3354' => [6, 6, true], '3355' => [6, 6, true], '3360' => [6, 6, true], '3361' => [6, 6, true],
        '3362' => [6, 6, true], '3363' => [6, 6, true], '3364' => [6, 6, true], '3365' => [6, 6, true],
        '3370' => [6, 6, true], '3371' => [6, 6, true], '3372' => [6, 6, true], '3373' => [6, 6, true],
        '3374' => [6, 6, true], '3375' => [6, 6, true], '3400' => [6, 6, true], '3401' => [6, 6, true],
        '3402' => [6, 6, true], '3403' => [6, 6, true], '3404' => [6, 6, true], '3405' => [6, 6, true],
        '3410' => [6, 6, true], '3411' => [6, 6, true], '3412' => [6, 6, true], '3413' => [6, 6, true],
        '3414' => [6, 6, true], '3415' => [6, 6, true], '3420' => [6, 6, true], '3421' => [6, 6, true],
        '3422' => [6, 6, true], '3423' => [6, 6, true], '3424' => [6, 6, true], '3425' => [6, 6, true],
        '3430' => [6, 6, true], '3431' => [6, 6, true], '3432' => [6, 6, true], '3433' => [6, 6, true],
        '3434' => [6, 6, true], '3435' => [6, 6, true], '3440' => [6, 6, true], '3441' => [6, 6, true],
        '3442' => [6, 6, true], '3443' => [6, 6, true], '3444' => [6, 6, true], '3445' => [6, 6, true],
        '3450' => [6, 6, true], '3451' => [6, 6, true], '3452' => [6, 6, true], '3453' => [6, 6, true],
        '3454' => [6, 6, true], '3455' => [6, 6, true], '3460' => [6, 6, true], '3461' => [6, 6, true],
        '3462' => [6, 6, true], '3463' => [6, 6, true], '3464' => [6, 6, true], '3465' => [6, 6, true],
        '3470' => [6, 6, true], '3471' => [6, 6, true], '3472' => [6, 6, true], '3473' => [6, 6, true],
        '3474' => [6, 6, true], '3475' => [6, 6, true], '3480' => [6, 6, true], '3481' => [6, 6, true],
        '3482' => [6, 6, true], '3483' => [6, 6, true], '3484' => [6, 6, true], '3485' => [6, 6, true],
        '3490' => [6, 6, true], '3491' => [6, 6, true], '3492' => [6, 6, true], '3493' => [6, 6, true],
        '3494' => [6, 6, true], '3495' => [6, 6, true], '3500' => [6, 6, true], '3501' => [6, 6, true],
        '3502' => [6, 6, true], '3503' => [6, 6, true], '3504' => [6, 6, true], '3505' => [6, 6, true],
        '3510' => [6, 6, true], '3511' => [6, 6, true], '3512' => [6, 6, true], '3513' => [6, 6, true],
        '3514' => [6, 6, true], '3515' => [6, 6, true], '3520' => [6, 6, true], '3521' => [6, 6, true],
        '3522' => [6, 6, true], '3523' => [6, 6, true], '3524' => [6, 6, true], '3525' => [6, 6, true],
        '3530' => [6, 6, true], '3531' => [6, 6, true], '3532' => [6, 6, true], '3533' => [6, 6, true],
        '3534' => [6, 6, true], '3535' => [6, 6, true], '3540' => [6, 6, true], '3541' => [6, 6, true],
        '3542' => [6, 6, true], '3543' => [6, 6, true], '3544' => [6, 6, true], '3545' => [6, 6, true],
        '3550' => [6, 6, true], '3551' => [6, 6, true], '3552' => [6, 6, true], '3553' => [6, 6, true],
        '3554' => [6, 6, true], '3555' => [6, 6, true], '3560' => [6, 6, true], '3561' => [6, 6, true],
        '3562' => [6, 6, true], '3563' => [6, 6, true], '3564' => [6, 6, true], '3565' => [6, 6, true],
        '3570' => [6, 6, true], '3571' => [6, 6, true], '3572' => [6, 6, true], '3573' => [6, 6, true],
        '3574' => [6, 6, true], '3575' => [6, 6, true], '3600' => [6, 6, true], '3601' => [6, 6, true],
        '3602' => [6, 6, true], '3603' => [6, 6, true], '3604' => [6, 6, true], '3605' => [6, 6, true],
        '3610' => [6, 6, true], '3611' => [6, 6, true], '3612' => [6, 6, true], '3613' => [6, 6, true],
        '3614' => [6, 6, true], '3615' => [6, 6, true], '3620' => [6, 6, true], '3621' => [6, 6, true],
        '3622' => [6, 6, true], '3623' => [6, 6, true], '3624' => [6, 6, true], '3625' => [6, 6, true],
        '3630' => [6, 6, true], '3631' => [6, 6, true], '3632' => [6, 6, true], '3633' => [6, 6, true],
        '3634' => [6, 6, true], '3635' => [6, 6, true], '3640' => [6, 6, true], '3641' => [6, 6, true],
        '3642' => [6, 6, true], '3643' => [6, 6, true], '3644' => [6, 6, true], '3645' => [6, 6, true],
        '3650' => [6, 6, true], '3651' => [6, 6, true], '3652' => [6, 6, true], '3653' => [6, 6, true],
        '3654' => [6, 6, true], '3655' => [6, 6, true], '3660' => [6, 6, true], '3661' => [6, 6, true],
        '3662' => [6, 6, true], '3663' => [6, 6, true], '3664' => [6, 6, true], '3665' => [6, 6, true],
        '3670' => [6, 6, true], '3671' => [6, 6, true], '3672' => [6, 6, true], '3673' => [6, 6, true],
        '3674' => [6, 6, true], '3675' => [6, 6, true], '3680' => [6, 6, true], '3681' => [6, 6, true],
        '3682' => [6, 6, true], '3683' => [6, 6, true], '3684' => [6, 6, true], '3685' => [6, 6, true],
        '3690' => [6, 6, true], '3691' => [6, 6, true], '3692' => [6, 6, true], '3693' => [6, 6, true],
        '3694' => [6, 6, true], '3695' => [6, 6, true], '3900' => [1, 15, false], '3901' => [1, 15, false],
        '3902' => [1, 15, false], '3903' => [1, 15, false], '3904' => [1, 15, false], '3905' => [1, 15, false],
        '3906' => [1, 15, false], '3907' => [1, 15, false], '3908' => [1, 15, false], '3909' => [1, 15, false],
        '3910' => [4, 18, false], '3911' => [4, 18, false], '3912' => [4, 18, false], '3913' => [4, 18, false],
        '3914' => [4, 18, false], '3915' => [4, 18, false], '3916' => [4, 18, false], '3917' => [4, 18, false],
        '3918' => [4, 18, false], '3919' => [4, 18, false], '3920' => [1, 15, false], '3921' => [1, 15, false],
        '3922' => [1, 15, false], '3923' => [1, 15, false], '3924' => [1, 15, false], '3925' => [1, 15, false],
        '3926' => [1, 15, false], '3927' => [1, 15, false], '3928' => [1, 15, false], '3929' => [1, 15, false],
        '3930' => [4, 18, false], '3931' => [4, 18, false], '3932' => [4, 18, false], '3933' => [4, 18, false],
        '3934' => [4, 18, false], '3935' => [4, 18, false], '3936' => [4, 18, false], '3937' => [4, 18, false],
        '3938' => [4, 18, false], '3939' => [4, 18, false], '3940' => [4, 4, false], '3941' => [4, 4, false],
        '3942' => [4, 4, false], '3943' => [4, 4, false], '3950' => [6, 6, false], '3951' => [6, 6, false],
        '3952' => [6, 6, false], '3953' => [6, 6, false], '3954' => [6, 6, false], '3955' => [6, 6, false],
        '4300' => [1, 35, false], '4301' => [1, 35, false], '4302' => [1, 70, false], '4303' => [1, 70, false],
        '4304' => [1, 70, false], '4305' => [1, 70, false], '4306' => [1, 70, false], '4307' => [2, 2, false],
        '4308' => [1, 30, false], '4309' => [20, 20, false], '4310' => [1, 35, false], '4311' => [1, 35, false],
        '4312' => [1, 70, false], '4313' => [1, 70, false], '4314' => [1, 70, false], '4315' => [1, 70, false],
        '4316' => [1, 70, false], '4317' => [2, 2, false], '4318' => [1, 20, false], '4319' => [1, 30, false],
        '4320' => [1, 35, false], '4321' => [1, 1, false], '4322' => [1, 1, false], '4323' => [1, 1, false],
        '4324' => [10, 10, false], '4325' => [10, 10, false], '4326' => [6, 6, false], '4330' => [6, 7, false],
        '4331' => [6, 7, false], '4332' => [6, 7, false], '4333' => [6, 7, false], '7001' => [13, 13, false],
        '7002' => [1, 30, false], '7003' => [10, 10, false], '7004' => [1, 4, false], '7005' => [1, 12, false],
        '7006' => [6, 6, false], '7007' => [6, 12, false], '7008' => [1, 3, false], '7009' => [1, 10, false],
        '7010' => [1, 2, false], '7011' => [6, 10, false], '7020' => [1, 20, false], '7021' => [1, 20, false],
        '7022' => [1, 20, false], '7023' => [1, 30, false], '7030' => [4, 30, false], '7031' => [4, 30, false],
        '7032' => [4, 30, false], '7033' => [4, 30, false], '7034' => [4, 30, false], '7035' => [4, 30, false],
        '7036' => [4, 30, false], '7037' => [4, 30, false], '7038' => [4, 30, false], '7039' => [4, 30, false],
        '7040' => [4, 4, false], '7041' => [1, 4, false], '7230' => [3, 30, false], '7231' => [3, 30, false],
        '7232' => [3, 30, false], '7233' => [3, 30, false], '7234' => [3, 30, false], '7235' => [3, 30, false],
        '7236' => [3, 30, false], '7237' => [3, 30, false], '7238' => [3, 30, false], '7239' => [3, 30, false],
        '7240' => [1, 20, false], '7241' => [2, 2, false], '7242' => [1, 25, false], '7250' => [8, 8, false],
        '7251' => [12, 12, false], '7252' => [1, 1, false], '7253' => [1, 40, false], '7254' => [1, 40, false],
        '7255' => [1, 10, false], '7256' => [1, 90, false], '7257' => [1, 70, false], '7258' => [3, 3, false],
        '7259' => [1, 40, false], '8001' => [14, 14, false], '8002' => [1, 20, false], '8003' => [14, 30, false],
        '8004' => [1, 30, false], '8005' => [6, 6, false], '8006' => [18, 18, false], '8007' => [1, 34, false],
        '8008' => [8, 12, false], '8009' => [1, 50, false], '8010' => [1, 30, false], '8011' => [1, 12, false],
        '8012' => [1, 20, false], '8013' => [1, 25, false], '8014' => [1, 25, false], '8017' => [18, 18, false],
        '8018' => [18, 18, false], '8019' => [1, 10, false], '8020' => [1, 25, false], '8026' => [18, 18, false],
        '8030' => [1, 90, false], '8040' => [15, 15, false], '8041' => [15, 15, false], '8042' => [32, 32, false],
        '8043' => [18, 20, false], '8110' => [1, 70, false], '8111' => [4, 4, false], '8112' => [1, 70, false],
        '8200' => [1, 70, false],
    ];

    /**
     * The three identifiers whose legal lengths are a set, not a range.
     *
     * (7007) is a harvest date: one date of six digits or two of six each.
     * (7011) is a test-by date with an optional time. (8008) is a date and
     * time to the hour, minute or second. Nothing between those values is
     * legal, so a range would accept data no scanner will read back as what
     * was meant.
     *
     * Numeric keys narrow to integers here as they do in IDENTIFIERS; the
     * subscript narrows the same way, so lookups are unaffected.
     *
     * @var array<int|string, list<int>>
     */
    public const LENGTH_SETS = [
        '7007' => [6, 12],
        '7011' => [6, 10],
        '8008' => [8, 10, 12],
    ];

    /** The shortest and longest an identifier can be. */
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 4;

    /**
     * Every identifier, as the strings they are written as.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(strval(...), array_keys(self::IDENTIFIERS));
    }

    /** Whether $ai is an application identifier at all. */
    public static function exists(string $ai): bool
    {
        return isset(self::IDENTIFIERS[$ai]);
    }

    /**
     * The identifier at the start of $digits, longest match first.
     *
     * Identifiers are not a prefix code — (01) is one and so is (010)'s
     * neighbourhood — so the length has to be resolved against the table
     * rather than assumed from the first two digits.
     */
    public static function at(string $digits): ?string
    {
        for ($length = self::MAX_LENGTH; $length >= self::MIN_LENGTH; $length--) {
            $candidate = substr($digits, 0, $length);
            if (\strlen($candidate) === $length && self::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every data length $ai accepts, ascending.
     *
     * @return list<int>
     */
    public static function lengths(string $ai): array
    {
        if (isset(self::LENGTH_SETS[$ai])) {
            return self::LENGTH_SETS[$ai];
        }

        [$min, $max] = self::IDENTIFIERS[$ai] ?? throw new \InvalidArgumentException(
            sprintf('Not a GS1 application identifier: (%s)', $ai)
        );

        return range($min, $max);
    }

    /** Whether $ai accepts data of exactly this length. */
    public static function accepts(string $ai, int $length): bool
    {
        return \in_array($length, self::lengths($ai), true);
    }

    /**
     * Whether an FNC1 must follow this identifier's data.
     *
     * True for everything not on GS1's predefined-length list, whatever its
     * length happens to be.
     */
    public static function needsSeparator(string $ai): bool
    {
        $entry = self::IDENTIFIERS[$ai] ?? throw new \InvalidArgumentException(
            sprintf('Not a GS1 application identifier: (%s)', $ai)
        );

        return !$entry[2];
    }
}
