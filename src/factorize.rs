// Copyright 2026 Daniil Gentili <daniil@daniil.it>
// SPDX-License-Identifier: AGPL-3.0-only
//! Factorization of 64-bit integers (MTProto's pq, in the auth key exchange): deterministic
//! Miller-Rabin, trial division of the small primes, then Brent's variant of Pollard's rho with
//! batched gcds, in Montgomery form. Every u64 works. (SQUFOF, with multipliers and a modular
//! square filter, was 2x slower on live MTProto pq, with a worse tail: 4-6.5 ms p99 against 1.2-2.4.)

fn gcd(mut a: u64, mut b: u64) -> u64 {
    while b != 0 {
        (a, b) = (b, a % b);
    }
    a
}

/// Deterministic for every u64 with these bases. In Montgomery form: no 128-bit division per product.
pub fn is_prime(n: u64) -> bool {
    const BASES: [u64; 12] = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37];
    if n < 2 {
        return false;
    }
    for p in BASES {
        if n.is_multiple_of(p) {
            return n == p;
        }
    }
    let (mut d, mut s) = (n - 1, 0);
    while d.is_multiple_of(2) {
        d /= 2;
        s += 1;
    }
    let m = Montgomery::new(n);
    let (one, minus_one) = (m.to_mont(1), m.to_mont(n - 1));
    'bases: for a in BASES {
        let mut x = m.pow(m.to_mont(a), d);
        if x == one || x == minus_one {
            continue;
        }
        for _ in 1..s {
            x = m.mul(x, x);
            if x == minus_one {
                continue 'bases;
            }
        }
        return false;
    }
    true
}

/// Arithmetic modulo an odd `n` in Montgomery form (R = 2^64): a product is two 64x64 multiplications
/// and a subtraction, instead of a 128-bit division.
struct Montgomery {
    n: u64,
    /// n^-1 mod 2^64.
    inv: u64,
    /// R^2 mod n: to_mont() is a product by it (the one 128-bit division is here).
    r2: u64,
}
impl Montgomery {
    fn new(n: u64) -> Self {
        debug_assert!(n & 1 == 1);
        // Newton's iteration doubles the correct low bits: 3 (n * n = 1 mod 8 for odd n), then 64.
        let mut inv = n;
        for _ in 0..5 {
            inv = inv.wrapping_mul(2u64.wrapping_sub(n.wrapping_mul(inv)));
        }
        let r1 = ((1u128 << 64) % u128::from(n)) as u64;
        let r2 = (u128::from(r1) * u128::from(r1) % u128::from(n)) as u64;
        Self { n, inv, r2 }
    }
    /// t / R mod n, for t < n * R: (t - m n) / R with m = t n^-1 mod R, whose low halves cancel.
    fn reduce(&self, t: u128) -> u64 {
        let m = (t as u64).wrapping_mul(self.inv);
        let mn = u128::from(m) * u128::from(self.n);
        let (hi, borrow) = ((t >> 64) as u64).overflowing_sub((mn >> 64) as u64);
        if borrow {
            hi.wrapping_add(self.n)
        } else {
            hi
        }
    }
    fn mul(&self, a: u64, b: u64) -> u64 {
        self.reduce(u128::from(a) * u128::from(b))
    }
    fn to_mont(&self, a: u64) -> u64 {
        self.mul(a % self.n, self.r2)
    }
    /// base^exp, in Montgomery form (base too).
    fn pow(&self, mut base: u64, mut exp: u64) -> u64 {
        let mut result = self.to_mont(1);
        while exp > 0 {
            if exp & 1 == 1 {
                result = self.mul(result, base);
            }
            base = self.mul(base, base);
            exp >>= 1;
        }
        result
    }
    fn add(&self, a: u64, b: u64) -> u64 {
        let (s, carry) = a.overflowing_add(b);
        if carry || s >= self.n {
            s.wrapping_sub(self.n)
        } else {
            s
        }
    }
}

/// A nontrivial factor of the odd composite `n`: Brent's rho, x -> x^2 + c in Montgomery form
/// (R is coprime to n, so the gcds are unchanged), gcds batched by 128 steps.
fn rho(n: u64) -> u64 {
    let m = Montgomery::new(n);
    for c in 1..n {
        let c = m.to_mont(c);
        let f = |x: u64| m.add(m.mul(x, x), c);
        let (mut y, mut r, mut q, mut g) = (m.to_mont(2), 1u64, m.to_mont(1), 1u64);
        let (mut x, mut ys) = (y, y);
        while g == 1 {
            x = y;
            for _ in 0..r {
                y = f(y);
            }
            let mut k = 0;
            while k < r && g == 1 {
                ys = y;
                for _ in 0..128.min(r - k) {
                    y = f(y);
                    q = m.mul(q, x.abs_diff(y));
                }
                g = gcd(q, n);
                k += 128;
            }
            r *= 2;
        }
        if g == n {
            // The batch overshot: step one at a time from its start.
            loop {
                ys = f(ys);
                g = gcd(x.abs_diff(ys), n);
                if g > 1 {
                    break;
                }
            }
        }
        if g != n {
            return g;
        }
    }
    unreachable!("rho found no factor of a composite")
}

/// The smallest prime factor of `n`, or None if `n` is below 2 or prime.
pub fn smallest_prime_factor(n: u64) -> Option<u64> {
    if n < 4 || is_prime(n) {
        return None;
    }
    // Odd n only past this point (Montgomery needs an odd modulus); small primes first.
    for p in [2u64, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47] {
        if n.is_multiple_of(p) {
            return Some(p);
        }
    }
    let mut pending = vec![n];
    let mut smallest = u64::MAX;
    while let Some(m) = pending.pop() {
        if is_prime(m) {
            smallest = smallest.min(m);
        } else {
            let d = rho(m);
            pending.extend([d, m / d]);
        }
    }
    Some(smallest)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn known_factorizations() {
        // MTProto's documented example, and Telegram-sized semiprimes (two ~31-bit primes).
        assert_eq!(smallest_prime_factor(0x17ED48941A08F981), Some(0x494C553B));
        assert_eq!(
            smallest_prime_factor(1_724_114_033_281_923_457),
            Some(1_229_739_323)
        );
        assert_eq!(
            smallest_prime_factor(2_147_483_647 * 2_147_483_629),
            Some(2_147_483_629)
        );
        // Small and repeated factors, the largest u64 semiprime of two 32-bit primes, and non-composites.
        assert_eq!(smallest_prime_factor(1 << 40), Some(2));
        assert_eq!(smallest_prime_factor(997 * 997 * 1_000_003), Some(997));
        assert_eq!(
            smallest_prime_factor(4_294_967_291 * 4_294_967_279),
            Some(4_294_967_279)
        );
        for n in [0, 1, 2, 3, 5, 2_147_483_647, 18_446_744_073_709_551_557] {
            assert_eq!(smallest_prime_factor(n), None, "{n}");
        }
    }

    #[test]
    fn random_semiprimes() {
        // A small xorshift: deterministic, no dependency.
        let mut s = 0x9E37_79B9_7F4A_7C15u64;
        let mut next = move || {
            s ^= s << 13;
            s ^= s >> 7;
            s ^= s << 17;
            s
        };
        let mut prime = |bits: u32| loop {
            let c = (next() >> (64 - bits)) | (1 << (bits - 1)) | 1;
            if is_prime(c) {
                return c;
            }
        };
        for _ in 0..200 {
            let (p, q) = (prime(31), prime(32));
            assert_eq!(smallest_prime_factor(p * q), Some(p.min(q)), "{p} * {q}");
        }
    }
}
