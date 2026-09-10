<?php

namespace Usaepay;

/**
 * A transaction lookup ran out of listing pages before it could prove the
 * orderid is absent. The charge may or may not exist: callers must not send
 * another one until a later lookup is conclusive.
 */
class ReconciliationInconclusiveException extends GatewayException {
}
