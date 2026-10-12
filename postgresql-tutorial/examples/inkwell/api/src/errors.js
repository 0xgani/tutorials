// Errors that become HTTP responses. Database errors are translated by their SQLSTATE code (chapter 22).
export class HttpError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

// Inkwell's own codes (db/migrations/002_functions.sql) carry messages written for users.
const CUSTOM = {
  IW001: [409, 'out_of_stock'],
  IW002: [401, 'not_signed_in'],
  IW003: [404, 'not_found'],
  IW004: [409, 'not_cancellable'],
};

const CONSTRAINT_MESSAGES = {
  reviews_product_id_customer_id_key: 'you have already reviewed this product',
  reviews_product_id_fkey: 'product not found',
};

export function toHttpError(err) {
  if (err instanceof HttpError) return err;
  if (err.code in CUSTOM) {
    const [status, code] = CUSTOM[err.code];
    return new HttpError(status, code, err.message);
  }
  switch (err.code) {
    case '23505': // unique_violation
      return new HttpError(409, 'conflict', CONSTRAINT_MESSAGES[err.constraint] ?? 'already exists');
    case '23503': // foreign_key_violation
      return new HttpError(404, 'not_found', CONSTRAINT_MESSAGES[err.constraint] ?? 'a referenced row does not exist');
    case '22023': // invalid_parameter_value (raised by place_order)
      return new HttpError(400, 'invalid_request', err.message);
    case '22P02': // invalid_text_representation, e.g. "abc" for a number
    case '23514': // check_violation
      return new HttpError(400, 'invalid_request', 'invalid value');
    case '57014': // query_canceled: statement_timeout
      return new HttpError(503, 'timeout', 'the request took too long, please try again');
    default:
      return null; // unexpected: the caller logs it and answers 500 without details
  }
}
