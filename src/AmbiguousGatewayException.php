<?php

namespace Usaepay;

/**
 * The gateway may have received the request, but no reliable result was read.
 *
 * Callers must reconcile (see GatewayClient::findTransactionByOrderId) before
 * retrying a charge.
 */
class AmbiguousGatewayException extends GatewayException {
}
