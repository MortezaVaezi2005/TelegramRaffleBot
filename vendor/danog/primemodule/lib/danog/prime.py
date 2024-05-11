import sys
from math import log

# Multiple Polynomial Quadratic Sieve
def mpqs(n, verbose=False):
  if verbose:
    time1 = clock()

  root_n = isqrt(n)
  root_2n = isqrt(n+n)

  # formula chosen by experimentation
  # seems to be close to optimal for n < 10^50
  bound = int(5 * log(n, 10)**2)

  prime = []
  mod_root = []
  log_p = []
  num_prime = 0

  # find a number of small primes for which n is a quadratic residue
  p = 2
  while p < bound or num_prime < 3:

    # legendre (n|p) is only defined for odd p
    if p > 2:
      leg = legendre(n, p)
    else:
      leg = n & 1
    if leg == 1:
      prime += [p]
      mod_root += [int(mod_sqrt(n, p))]
      log_p += [log(p, 10)]
      num_prime += 1
    elif leg == 0:
      return p

    p = next_prime(p)

  # size of the sieve
  x_max = len(prime)*60

  # maximum value on the sieved range
  m_val = (x_max * root_2n) >> 1

  # fudging the threshold down a bit makes it easier to find powers of primes as factors
  # as well as partial-partial relationships, but it also makes the smoothness check slower.
  # there's a happy medium somewhere, depending on how efficient the smoothness check is
  thresh = log(m_val, 10) * 0.735

  # skip small primes. they contribute very little to the log sum
  # and add a lot of unnecessary entries to the table
  # instead, fudge the threshold down a bit, assuming ~1/4 of them pass
  min_prime = int(thresh*3)
  fudge = sum(log_p[i] for i,p in enumerate(prime) if p < min_prime)/4
  thresh -= fudge

  smooth = []
  used_prime = set()
  partial = {}
  num_smooth = 0
  num_used_prime = 0
  num_partial = 0
  num_poly = 0
  root_A = isqrt(root_2n / x_max)

  while True:
    # find an integer value A such that:
    # A is =~ sqrt(2*n) / x_max
    # A is a perfect square
    # sqrt(A) is prime, and n is a quadratic residue mod sqrt(A)
    while True:
      root_A = next_prime(root_A)
      leg = legendre(n, root_A)
      if leg == 1:
        break
      elif leg == 0:
        return root_A

    A = root_A * root_A

    # solve for an adequate B
    # B*B is a quadratic residue mod n, such that B*B-A*C = n
    # this is unsolvable if n is not a quadratic residue mod sqrt(A)
    b = mod_sqrt(n, root_A)
    B = (b + (n - b*b) * mod_inv(b + b, root_A))%A

    # B*B-A*C = n <=> C = (B*B-n)/A
    C = (B*B - n) / A

    num_poly += 1

    # sieve for prime factors
    sums = [0.0]*(2*x_max)
    i = 0
    for p in prime:
      if p < min_prime:
        i += 1
        continue
      logp = log_p[i]

      inv_A = mod_inv(A, p)
      # modular root of the quadratic
      a = int(((mod_root[i] - B) * inv_A)%p)
      b = int(((p - mod_root[i] - B) * inv_A)%p)

      k = 0
      while k < x_max:
        if k+a < x_max:
          sums[k+a] += logp
        if k+b < x_max:
          sums[k+b] += logp
        if k:
          sums[k-a+x_max] += logp
          sums[k-b+x_max] += logp

        k += p
      i += 1

    # check for smooths
    i = 0
    for v in sums:
      if v > thresh:
        x = x_max-i if i > x_max else i
        vec = set()
        sqr = []
        # because B*B-n = A*C
        # (A*x+B)^2 - n = A*A*x*x+2*A*B*x + B*B - n
        #               = A*(A*x*x+2*B*x+C)
        # gives the congruency
        # (A*x+B)^2 = A*(A*x*x+2*B*x+C) (mod n)
        # because A is chosen to be square, it doesn't need to be sieved
        val = sieve_val = A*x*x + 2*B*x + C

        if sieve_val < 0:
          vec = set([-1])
          sieve_val = -sieve_val

        for p in prime:
          while sieve_val%p == 0:
            if p in vec:
              # keep track of perfect square factors
              # to avoid taking the sqrt of a gigantic number at the end
              sqr += [p]
            vec ^= set([p])
            sieve_val = int(sieve_val / p)

        if sieve_val == 1:
          # smooth
          smooth += [(vec, (sqr, (A*x+B), root_A))]
          used_prime |= vec
        elif sieve_val in partial:
          # combine two partials to make a (xor) smooth
          # that is, every prime factor with an odd power is in our factor base
          pair_vec, pair_vals = partial[sieve_val]
          sqr += list(vec & pair_vec) + [sieve_val]
          vec ^= pair_vec
          smooth += [(vec, (sqr + pair_vals[0], (A*x+B)*pair_vals[1], root_A*pair_vals[2]))]
          used_prime |= vec
          num_partial += 1
        else:
          # save partial for later pairing
          partial[sieve_val] = (vec, (sqr, A*x+B, root_A))
      i += 1

    num_smooth = len(smooth)
    num_used_prime = len(used_prime)


    if num_smooth > num_used_prime:

      used_prime_list = sorted(list(used_prime))

      # set up bit fields for gaussian elimination
      masks = []
      mask = 1
      bit_fields = [0]*num_used_prime
      for vec, vals in smooth:
        masks += [mask]
        i = 0
        for p in used_prime_list:
          if p in vec: bit_fields[i] |= mask
          i += 1
        mask <<= 1

      # row echelon form
      col_offset = 0
      null_cols = []
      for col in xrange(num_smooth):
        pivot = col-col_offset == num_used_prime or bit_fields[col-col_offset] & masks[col] == 0
        for row in xrange(col+1-col_offset, num_used_prime):
          if bit_fields[row] & masks[col]:
            if pivot:
              bit_fields[col-col_offset], bit_fields[row] = bit_fields[row], bit_fields[col-col_offset]
              pivot = False
            else:
              bit_fields[row] ^= bit_fields[col-col_offset]
        if pivot:
          null_cols += [col]
          col_offset += 1

      # reduced row echelon form
      for row in xrange(num_used_prime):
        # lowest set bit
        mask = bit_fields[row] & -bit_fields[row]
        for up_row in xrange(row):
          if bit_fields[up_row] & mask:
            bit_fields[up_row] ^= bit_fields[row]

      # check for non-trivial congruencies
      for col in null_cols:
        all_vec, (lh, rh, rA) = smooth[col]
        lhs = lh   # sieved values (left hand side)
        rhs = [rh] # sieved values - n (right hand side)
        rAs = [rA] # root_As (cofactor of lhs)
        i = 0
        for field in bit_fields:
          if field & masks[col]:
            vec, (lh, rh, rA) = smooth[i]
            lhs += list(all_vec & vec) + lh
            all_vec ^= vec
            rhs += [rh]
            rAs += [rA]
          i += 1

        factor = gcd(list_prod(rAs)*list_prod(lhs) - list_prod(rhs), n)
        if factor != 1 and factor != n:
          break
      else:
        continue
      break

  return factor

# divide and conquer list product
def list_prod(a):
  size = len(a)
  if size == 1:
    return a[0]
  return list_prod(a[:size>>1]) * list_prod(a[size>>1:])

# greatest common divisor of a and b
def gcd(a, b):
  while b:
    a, b = b, a%b
  return a

# modular inverse of a mod m
def mod_inv(a, m):
  a = int(a%m)
  x, u = 0, 1
  while a:
    x, u = u, x - (m/a)*u
    m, a = a, m%a
  return x

# legendre symbol (a|m)
# note: returns m-1 if a is a non-residue, instead of -1
def legendre(a, m):
  return pow(a, (m-1) >> 1, m)

# modular sqrt(n) mod p
# p must be prime
def mod_sqrt(n, p):
  a = n%p
  if p%4 == 3:
    return pow(a, (p+1) >> 2, p)
  elif p%8 == 5:
    v = pow(a << 1, (p-5) >> 3, p)
    i = ((a*v*v << 1) % p) - 1
    return (a*v*i)%p
  elif p%8 == 1:
    # Shank's method
    q = p-1
    e = 0
    while q&1 == 0:
      e += 1
      q >>= 1

    n = 2
    while legendre(n, p) != p-1:
      n += 1

    w = pow(a, q, p)
    x = pow(a, (q+1) >> 1, p)
    y = pow(n, q, p)
    r = e
    while True:
      if w == 1:
        return x

      v = w
      k = 0
      while v != 1 and k+1 < r:
        v = (v*v)%p
        k += 1

      if k == 0:
        return x

      d = pow(y, 1 << (r-k-1), p)
      x = (x*d)%p
      y = (d*d)%p
      w = (w*y)%p
      r = k
  else: # p == 2
    return a

#integer sqrt of n
def isqrt(n):
  n = int(n)
  c = int(n*4/3)
  d = c.bit_length()

  a = d>>1
  if d&1:
    x = 1 << a
    y = (x + (n >> a)) >> 1
  else:
    x = (3 << a) >> 2
    y = (x + (c >> a)) >> 1

  if x != y:
    x = y
    y = int(x + n/x) >> 1
    while y < x:
      x = y
      y = int(x + n/x) >> 1
  return x

# strong probable prime
def is_sprp(n, b=2):
  if n < 2: return False
  d = n-1
  s = 0
  while d&1 == 0:
    s += 1
    d >>= 1

  x = pow(b, d, n)
  if x == 1 or x == n-1:
    return True

  for r in xrange(1, s):
    x = (x * x)%n
    if x == 1:
      return False
    elif x == n-1:
      return True

  return False

# lucas probable prime
# assumes D = 1 (mod 4), (D|n) = -1
def is_lucas_prp(n, D):
  P = 1
  Q = (1-D) >> 2

  # n+1 = 2**r*s where s is odd
  s = n+1
  r = 0
  while s&1 == 0:
    r += 1
    s >>= 1

  # calculate the bit reversal of (odd) s
  # e.g. 19 (10011) <=> 25 (11001)
  t = 0
  while s:
    if s&1:
      t += 1
      s -= 1
    else:
      t <<= 1
      s >>= 1

  # use the same bit reversal process to calculate the sth Lucas number
  # keep track of q = Q**n as we go
  U = 0
  V = 2
  q = 1
  # mod_inv(2, n)
  inv_2 = (n+1) >> 1
  while t:
    if t&1:
      # U, V of n+1
      U, V = ((U + V) * inv_2)%n, ((D*U + V) * inv_2)%n
      q = (q * Q)%n
      t -= 1
    else:
      # U, V of n*2
      U, V = (U * V)%n, (V * V - 2 * q)%n
      q = (q * q)%n
      t >>= 1

  # double s until we have the 2**r*sth Lucas number
  while r:
    U, V = (U * V)%n, (V * V - 2 * q)%n
    q = (q * q)%n
    r -= 1

  # primality check
  # if n is prime, n divides the n+1st Lucas number, given the assumptions
  return U == 0

# primes less than 212
small_primes = set([
    2,  3,  5,  7, 11, 13, 17, 19, 23, 29,
   31, 37, 41, 43, 47, 53, 59, 61, 67, 71,
   73, 79, 83, 89, 97,101,103,107,109,113,
  127,131,137,139,149,151,157,163,167,173,
  179,181,191,193,197,199,211])

# pre-calced sieve of eratosthenes for n = 2, 3, 5, 7
indices = [
    1, 11, 13, 17, 19, 23, 29, 31, 37, 41,
   43, 47, 53, 59, 61, 67, 71, 73, 79, 83,
   89, 97,101,103,107,109,113,121,127,131,
  137,139,143,149,151,157,163,167,169,173,
  179,181,187,191,193,197,199,209]

# distances between sieve values
offsets = [
  10, 2, 4, 2, 4, 6, 2, 6, 4, 2, 4, 6,
   6, 2, 6, 4, 2, 6, 4, 6, 8, 4, 2, 4,
   2, 4, 8, 6, 4, 6, 2, 4, 6, 2, 6, 6,
   4, 2, 4, 6, 2, 6, 4, 2, 4, 2,10, 2]

max_int = 2147483647

# an 'almost certain' primality check
def is_prime(n):
  if n < 212:
    return n in small_primes

  for p in small_primes:
    if n%p == 0:
      return False

  # if n is a 32-bit integer, perform full trial division
  if n <= max_int:
    i = 211
    while i*i < n:
      for o in offsets:
        i += o
        if n%i == 0:
          return False
    return True

  # Baillie-PSW
  # this is technically a probabalistic test, but there are no known pseudoprimes
  if not is_sprp(n, 2): return False

  # idea shamelessly stolen from Mathmatica
  # if n is a 2-sprp and a 3-sprp, n is necessarily square-free
  if not is_sprp(n, 3): return False

  a = 5
  s = 2
  # if n is a perfect square, this will never terminate
  while legendre(a, n) != n-1:
    s = -s
    a = s-a
  return is_lucas_prp(n, a)

# next prime strictly larger than n
def next_prime(n):
  if n < 2:
    return 2
  # first odd larger than n
  n = (n + 1) | 1
  if n < 212:
    while True:
      if n in small_primes:
        return n
      n += 2

  # find our position in the sieve rotation via binary search
  x = int(n%210)
  s = 0
  e = 47
  m = 24
  while m != e:
    if indices[m] < x:
      s = m
      m = (s + e + 1) >> 1
    else:
      e = m
      m = (s + e) >> 1

  i = int(n + (indices[m] - x))
  # adjust offsets
  offs = offsets[m:] + offsets[:m]
  while True:
    for o in offs:
      if is_prime(i):
        return i
      i += o

print(mpqs(int(sys.argv[1])))
