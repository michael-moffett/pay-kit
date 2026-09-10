# frozen_string_literal: true

require "time"
require "date"

module PayCore
  # RFC 3339 date-time parser shared by solana-mpp and solana-x402.
  #
  # @see https://datatracker.ietf.org/doc/html/rfc3339 RFC 3339 Date and Time on the Internet
  module Rfc3339Parser
    # Strict RFC 3339 date-time (sec 5.6). Year is exactly 4 digits; T literal
    # accepted upper or lower (per parse SHOULD); time-secfrac is "." 1*DIGIT,
    # so the digit count is unbounded here and clamped after the match.
    REGEX = /\A
      (\d{4})-(\d{2})-(\d{2})         # full-date
      [Tt]
      (\d{2}):(\d{2}):(\d{2})         # partial-time
      (?:\.(\d+))?                    # time-secfrac
      (?:[Zz]|([+-]\d{2}):(\d{2}))    # time-offset
      \z/x
    private_constant :REGEX

    module_function

    # Parse an RFC 3339 timestamp into a Time, or nil when the input is
    # not a valid RFC 3339 date-time. Returns nil for any out-of-range
    # component so callers can fail-closed.
    def parse(value)
      return nil unless value.is_a?(String)

      match = REGEX.match(value)
      return nil unless match

      year, month, day = match[1].to_i, match[2].to_i, match[3].to_i
      hour, minute, second = match[4].to_i, match[5].to_i, match[6].to_i
      return nil if month < 1 || month > 12
      return nil if day < 1 || day > 31
      return nil if hour > 23 || minute > 59 || second > 60
      # time-numoffset bounds the offset at 23:59, but Time.iso8601 reads
      # +00:60 as +01:00 — a silent hour shift in the instant an expiry is
      # compared against, so the offset is ranged before it is delegated.
      return nil if match[8] && (match[8].delete("+-").to_i > 23 || match[9].to_i > 59)
      return nil if year > 9999
      return nil unless Date.valid_date?(year, month, day)

      # Rebuilt from the captures rather than delegated verbatim: Time.iso8601
      # rejects the lowercase t/z that sec 5.6 allows, keeps sub-nanosecond
      # secfrac as a Rational no other SDK can carry, and rolls seconds = 60
      # forward into the next minute instead of clamping it.
      leap_second = second == 60
      secfrac = match[7] ? ".#{match[7][0, 9]}" : ""
      secfrac = ".999999999" if leap_second
      offset = match[8] ? "#{match[8]}:#{match[9]}" : "Z"
      parsed = Time.iso8601("#{match[1]}-#{match[2]}-#{match[3]}T#{match[4]}:#{match[5]}:" \
                            "#{leap_second ? "59" : match[6]}#{secfrac}#{offset}")
      return parsed unless leap_second

      # RFC 3339 sec 5.7: a leap second only ever ends a UTC month, and the
      # offset is applied before that is judged. Matches the TypeScript SDK.
      utc = parsed.getutc
      return nil unless utc.hour == 23 && utc.min == 59 && utc.day == Date.new(utc.year, utc.month, -1).day

      parsed
    rescue ArgumentError
      nil
    end
  end
end
