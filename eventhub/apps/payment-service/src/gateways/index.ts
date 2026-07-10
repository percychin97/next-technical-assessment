import { PaymentGateway } from '../types';
import { StripeSimulator } from './StripeSimulator';
import { PayPalSimulator } from './PayPalSimulator';

const gateways: Record<string, PaymentGateway> = {
  stripe_simulator: new StripeSimulator(),
  paypal_simulator: new PayPalSimulator(),
};

export function getGateway(provider: string): PaymentGateway {
  const gateway = gateways[provider];
  if (!gateway) {
    throw new Error(`Unknown payment provider: ${provider}`);
  }
  return gateway;
}

export { StripeSimulator, PayPalSimulator };
