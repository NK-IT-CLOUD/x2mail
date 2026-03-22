<?php

namespace MailSo\Mail;

/**
 * PHPStan stub for MessageListParams.
 *
 * The real class uses __get/__set magic to expose protected int properties.
 * We declare them as public here so PHPStan validates property access correctly.
 *
 * @property int $iOffset  Exposed via __get/__set magic
 * @property int $iLimit   Exposed via __get/__set magic
 * @property int $iPrevUidNext
 * @property int $iThreadUid
 */
class MessageListParams
{
    public string $sFolderName = '';
    public string $sSearch = '';
    public string $sSort = '';
    public string $sThreadAlgorithm = '';
    public bool $bUseSort = true;
    public bool $bUseThreads = false;
    public bool $bHideDeleted = true;
    public bool $bSearchFuzzy = false;
}
