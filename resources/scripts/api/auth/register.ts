import http from '@/api/http';

export interface RegisterResponse {
    complete: boolean;
    intended?: string;
}

export interface RegisterData {
    email: string;
    username: string;
    password: string;
    passwordConfirmation: string;
}

export default ({ email, username, password, passwordConfirmation }: RegisterData): Promise<RegisterResponse> => {
    return new Promise((resolve, reject) => {
        http.get('/sanctum/csrf-cookie')
            .then(() =>
                http.post('/auth/register', {
                    email,
                    username,
                    password,
                    password_confirmation: passwordConfirmation,
                })
            )
            .then((response) => {
                if (!(response.data instanceof Object)) {
                    return reject(new Error('An error occurred while processing the registration request.'));
                }

                return resolve({
                    complete: response.data.data.complete,
                    intended: response.data.data.intended || undefined,
                });
            })
            .catch(reject);
    });
};
