<?php

namespace Pimple {
    class Container implements \ArrayAccess
    {
        public function offsetExists($offset): bool
        {
            return false;
        }

        public function offsetGet($offset): mixed
        {
            return null;
        }

        public function offsetSet($offset, $value): void
        {
        }

        public function offsetUnset($offset): void
        {
        }
    }
}

namespace FOSSBilling {
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;
    }
}

namespace FOSSBilling\Payment {
    interface JsonWebhookAuthenticatorInterface
    {
        public function authenticateJsonWebhook(array $data): void;
    }
}
