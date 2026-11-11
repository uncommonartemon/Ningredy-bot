// The server answers an account form with a short code where the page has words of its own
// in the visitor's language; any other complaint about a field reads as «check this field».
const known = { credentials: 'account.wrongCredentials', throttled: 'account.throttled', taken: 'account.emailTaken' };

export const accountError = (error, t) => {
    if (!error) return '';

    return t(known[error] ?? 'account.checkField');
};
