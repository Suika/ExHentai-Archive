<?php

/**
 * ExHentai refuses requests: the IP is temporarily banned, or it keeps warning about opening pages too fast.
 * Tasks stop their run when they get this (the next run continues); retrying would only extend the ban.
 */
class ExClient_BannedException extends Exception
{
}
