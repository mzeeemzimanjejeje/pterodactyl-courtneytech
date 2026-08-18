export interface CountryOption {
    code: string;
    name: string;
}

const regionCodes = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('region') : [];
const displayNames = new Intl.DisplayNames(['en'], { type: 'region' });

export const countryOptions: CountryOption[] = regionCodes
    .filter((code) => /^[A-Z]{2}$/.test(code))
    .map((code) => ({ code, name: displayNames.of(code) || code }))
    .filter(({ name }) => name !== 'undefined')
    .sort((a, b) => a.name.localeCompare(b.name));
