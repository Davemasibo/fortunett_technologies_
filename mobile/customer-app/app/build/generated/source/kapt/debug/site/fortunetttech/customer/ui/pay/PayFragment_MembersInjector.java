package site.fortunetttech.customer.ui.pay;

import dagger.MembersInjector;
import dagger.internal.DaggerGenerated;
import dagger.internal.InjectedFieldSignature;
import dagger.internal.QualifierMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.preferences.TokenPreferences;

@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class PayFragment_MembersInjector implements MembersInjector<PayFragment> {
  private final Provider<TokenPreferences> prefsProvider;

  public PayFragment_MembersInjector(Provider<TokenPreferences> prefsProvider) {
    this.prefsProvider = prefsProvider;
  }

  public static MembersInjector<PayFragment> create(Provider<TokenPreferences> prefsProvider) {
    return new PayFragment_MembersInjector(prefsProvider);
  }

  @Override
  public void injectMembers(PayFragment instance) {
    injectPrefs(instance, prefsProvider.get());
  }

  @InjectedFieldSignature("site.fortunetttech.customer.ui.pay.PayFragment.prefs")
  public static void injectPrefs(PayFragment instance, TokenPreferences prefs) {
    instance.prefs = prefs;
  }
}
