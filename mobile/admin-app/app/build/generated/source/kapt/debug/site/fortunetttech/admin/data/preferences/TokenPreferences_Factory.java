package site.fortunetttech.admin.data.preferences;

import android.content.Context;
import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;

@ScopeMetadata("javax.inject.Singleton")
@QualifierMetadata("dagger.hilt.android.qualifiers.ApplicationContext")
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
public final class TokenPreferences_Factory implements Factory<TokenPreferences> {
  private final Provider<Context> contextProvider;

  public TokenPreferences_Factory(Provider<Context> contextProvider) {
    this.contextProvider = contextProvider;
  }

  @Override
  public TokenPreferences get() {
    return newInstance(contextProvider.get());
  }

  public static TokenPreferences_Factory create(Provider<Context> contextProvider) {
    return new TokenPreferences_Factory(contextProvider);
  }

  public static TokenPreferences newInstance(Context context) {
    return new TokenPreferences(context);
  }
}
